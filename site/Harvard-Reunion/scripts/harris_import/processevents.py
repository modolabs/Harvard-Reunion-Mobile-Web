#!/usr/bin/env python
"""
Python 2.6 is required.

It automatically generates:
* a filtered version of the CSV with all the stuff we don't care removed.
* a users table CSV
* an events table CSV
* a user_events table CSV

Helpful convention:
  xxx_col = a single Column object
  xxx_cols = a ColumnGroup object (a collection of columns with header names)
  
Encoding:
* The data created is encoded as Latin-1. We change this to UTF-8 when we write
  out the data file and when we insert values into the database.

Hashing and Caching:
* The csvcolumns library has some exotic and unnecessary caching and hashing 
  code because it was meant to handle a different problem where the data sets
  were much larger, performance was really critical, and access was almost 
  entirely column-oriented. While a lot of that is tossed out the window for 
  this application, I didn't pull them out of the library.
"""
import os.path
import sqlite3
import string
import sys
from itertools import izip
from optparse import OptionParser
from StringIO import StringIO

from csvcolumns.columngroup import ColumnGroup
from csvcolumns.column import DataColumn
from csvcolumns.transform import MethodTransform

import config
import testdata
from testdata import anonymize_events, anonymize_users

def main(class_year, infile, db_path, anonymize=False, debug_mode=False):
    infile_cols = parse_doc(infile)
    all_cols = infile_cols + \
               non_harris_event_cols(class_year, infile_cols.num_rows)
    
    # Basic user info like name, graduating year, email
    user_cols = select_user_cols(all_cols)
    user_cols = anonymize_users(user_cols, class_year) if anonymize else user_cols

    # Extract event cols, keep the Event ID, strip Event Name, sort cols by ID
    event_cols = select_event_cols(all_cols).sort_columns()
    event_cols = anonymize_events(event_cols) if anonymize else event_cols

    # Remove orders that were voided by the user
    active = (user_cols + event_cols).reject_rows_by_value("status", "Voided")

    # Merge together rows that represent multiple orders from the same person 
    # by looking for orders with the same email address (it must be sorted so
    # that records to be merged are grouped together)
    sorted_by_email = active.sort_rows_by("email")
    merged, merge_log = sorted_by_email.merge_rows_by("email", 
                                                      merge_func=merge_rows)

    # Account for package deals where signing up for one event actually means
    # you're attending multiple ones -- even some that aren't in Harris
    final = add_packages(merged, class_year)

    # Now slice it up into tables...
    events_table = make_events_table(all_cols.column_names)
    users_table = final.select("user_id", "email", "status", "prefix",
                               "first_name", "last_name", "suffix", "class_year")
    users_events_table = make_users_events_table(event_cols.column_names, final)

    if debug_mode:
        final.write(db_path + ".csv")
        events_table.write(db_path + "-events.csv")
        users_table.write(db_path + "-users.csv")
        users_events_table.write(db_path + "-users_events.csv")

    # Write our DB...
    dbconn = sqlite3.connect(db_path)
    events_table.write_db_table(dbconn, "events", primary_key="event_id")
    users_table.write_db_table(dbconn, "users", primary_key="user_id",
                               indexes=["email", "first_name", "last_name"])
    users_events_table.write_db_table(dbconn, "users_events", 
                                      indexes=["user_id", "event_id", "value"])
    dbconn.close()
    
    return merge_log

#################### Parse and Extract ####################
def parse_doc(infile):
    return ColumnGroup.from_csv(infile, 
                                delimiter="\t",
                                force_unique_col_names=True,
                                encoding="latin-1")

def select_user_cols(col_grp):
    """Basic user information (each row is actually an order, so we can 
    potentially get the same user buying stuff multiple times)"""

    def _fix_email(email, first_name, last_name):
        """If it looks vaguely like an email, just normalize to lowercase. If 
        not (for cases like 'None', blanks, 'Not Available' or whatever, use
        first.last@example.com as a fake-but-unique value."""
        if "@" in email and "." in email and len(email) > 5:
            return email.lower()
        
        new_email = filter(lambda c: c.isalpha() or c in ["@", "."],
                           "%s.%s@example.com" % (first_name, last_name))
        return new_email.lower()

    user_cols = col_grp.select("email",
                               ("order_id", "user_id"),
                               "status",
                               ("bill_prefix", "prefix"),
                               ("bill_first_name", "first_name"),
                               ("bill_last_name", "last_name"),
                               ("bill_suffix", "suffix"),
                               "class_year")
    
    email_and_names_grp = user_cols.select("email", "first_name", "last_name")
    fixed_emails = DataColumn([_fix_email(email, first_name, last_name)
                               for email, first_name, last_name
                               in email_and_names_grp.iter_rows()])
    
    return user_cols.replace(email=fixed_emails)

def select_event_cols(col_grp):
    """All events are of the format "Special Dinner #2131230", and correspond to
    line items in the Harris order form. The ColumnGroup we return changes the
    format of the events to have only IDs, like "2131230".""" 
    def _reformat_event_name(event_header):
        event_id, event_name = parse_event_header(event_header)
        return event_id

    return col_grp.selectf(is_event_header, 
                           change_name_func=_reformat_event_name)

#################### Merge Records ####################

def merge_rows(row1, row2):
    """Returns a tuple that represents our merged row. Merge rules:
    1. If either is blank, use the one that has a value.
    2. Special case: for the user_id, take the first value -- this is likely to
       correspond to their first (and most complete) order.
    3. Special case: for the class_year, just take the last value.
    4. If the two values are numbers, add them
    5. If the two values are strings, the second row wins."""
    new_row = {}
    for key in row1:
        val1, val2 = row1[key], row2[key]
        if (not val1) or (not val2): # if one is blank, take non-blank...
            new_row[key] = val1 if val1 else val2
        elif key == "user_id":
            new_row[key] = val1
        elif key == "class_year":
            new_row[key] = val2
        elif val1.isdigit() and val2.isdigit(): # if both are digits, add
            new_row[key] = str(int(val1) + int(val2)) # we only store strings
        else:
            new_row[key] = val2

    return new_row

#################### Massaging Data (based on config) ####################
def is_event_header(s):
    return "#" in s

def parse_event_header(s):
    """return event_id, event_name tuple"""
    event_name, event_id = s.split("#")
    return event_id.strip(), event_name.strip()

def format_as_event_header(event_id, event_name):
    return "%s #%s" % (event_name, event_id)

def non_harris_event_cols(class_year, num_rows):
    """Add columns for non-Harris events. These can be auto-populated if Harris
    package events include them (look in config.py). They are initialized to all
    blank."""
    blank_col = DataColumn.init_with(num_rows, '')
    return ColumnGroup(
               [(format_as_event_header(event_id, "NH"), blank_col)
                for event_id in config.non_harris_events_for_year(class_year)]
           )

def add_packages(src_col_grp, class_year):
    """Return a new ColumnGroup that has the row values filled out for all 
    users attending events that are covered by package events. So if package
    event 100 maps to regular events 101, 102hr, 103; then the value for 
    row['100'] should be copied to row['101'], row['102hr'], and row['103']
    
    However, it's possible that row['101'] already has a value that indicates
    something *about* the event (like "What House am I staying at?"), in which
    case, we want to keep what's there. We only replace it with what's in the
    package if what's in the specific event is blank. Anything non-blank is
    assumed to mean that they're attending.
    """
    package_events = config.packages_for_year(class_year)
    
    def _new_row(old_row):
        row = old_row.copy()
        for package_event_id, event_ids in package_events.items():
            for event_id in event_ids:
                if not row[event_id]:
                    row[event_id] = row[package_event_id]
        return row
    
    return src_col_grp.transform_rows(_new_row)
    

#################### Write to CSV files ####################

def make_events_table(event_headers):
    """The column names are of the format "Friday Dinner #123123". We want to 
    take all the column names and make a table of event ids and descriptions"""
    events = [parse_event_header(header) 
              for header in event_headers if is_event_header(header)]
    return ColumnGroup.from_rows(["event_id", "name"], sorted(events))

def make_users_events_table(event_col_names, all_cols):
    """event_col_names is a list of all the column names that represent events
    (these can be alpha or numeric). This function assumes that all_cols has 
    already been sorted, merged, had voided records excluded, etc."""
    event_cols = all_cols.select(*event_col_names)
    rows = iterate_user_events(all_cols.user_id, event_cols)
    return ColumnGroup.from_rows(["user_id", "event_id", "value"], rows)

def iterate_user_events(user_id_col, event_cols):
    """Returns an iterable of (user_id, event_id, event_value) tuples.
    event_values can be numbers or freeform text -- it's user input driven."""
    event_ids = event_cols.column_names
    for user_id, event_row in izip(user_id_col, event_cols.iter_rows()):
        # Convert event_row to be a sequence of (event_id, event_value) tuples
        event_ids_values = zip(event_ids, event_row)
        events_for_user = [(event_id, event_value)
                           for (event_id, event_value) in event_ids_values
                           if event_value]
        for event_id, event_value in events_for_user:
            yield (user_id, event_id, event_value)


###########

def format_row(row):
    return "  " + ",".join(row) + "\n"

def format_merge_log(merge_log):
    output = StringIO()
    output.write("== Records merged: ==\n")
    for src_rows, merged_row in merge_log.items():
        output.write("Merged:\n")
        output.writelines(map(format_row, src_rows))
        output.write("Into:\n")
        output.write(format_row(merged_row))
    
    return output.getvalue()
    


if __name__ == '__main__':
    # We're passing in class_year explicitly instead of deriving it from the 
    # data file because of cases like Harvard and Radcliffe differntiating their
    # Harvard and Radcliffe grads (like 1961R), which doesn't come through in 
    # the Harris feed.
    usage = "Usage: %prog [options] class_year harris_input_file output_db_file"
    parser = OptionParser(usage=usage)
    parser.add_option("-a", "--anonymize", action="store_true", dest="anonymize",
                      help="Replace names and user-event details in the data " \
                           "file with generated test data.")
    parser.add_option("-d", "--debug", action="store_true", dest="debug_mode",
                      help="Generate debug CSV files that dump out the " \
                           "contents of the output database file.")
    opts, args = parser.parse_args()
    class_year, infile_name, outfile_name = args

    with open(infile_name, "U") as infile:
        merge_log = main(class_year, infile, outfile_name, 
                         opts.anonymize, opts.debug_mode)
        print format_merge_log(merge_log)



