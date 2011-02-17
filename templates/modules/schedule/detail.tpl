{include file="findInclude:common/header.tpl"}

{$firstSection = array_shift($sections)}
{$titleField = array_shift($firstSection)}

<div class="nonfocal">
  <h2>{include file="findInclude:common/listItem.tpl" item=$titleField}</h2>
  {foreach $firstSection as $field}
    {include file="findInclude:common/listItem.tpl" item=$field}
  {/foreach}
</div>
  
{if count($sections)}
  {foreach $sections as $fields}
    {include file="findInclude:common/navlist.tpl" navlistItems=$fields}
  {/foreach}
{/if}

{include file="findInclude:common/footer.tpl"}
