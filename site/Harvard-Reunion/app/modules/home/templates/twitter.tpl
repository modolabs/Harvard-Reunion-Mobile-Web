{include file="findInclude:common/templates/header.tpl" isModuleHome=true}

<div class="nonfocal">
  {block name="twitterHeader"}
    <a class="tweetButton" href="{$tweetURL}"><span class="tweetLink">tweet</span></a>
    <h2>{$hashtag}</h2>
  {/block}
</div>

<div id="autoupdateContainer">
  {include file="findInclude:modules/$moduleID/templates/twitterContent.tpl" posts=$posts}
</div>

{block name="twitterFooter"}
  <div class="nonfocal">
    <span class="smallprint">View tweets for {$hashtag} at <a href="{$twitterURL}">twitter.com</a></span>
  </div>
{/block}

{include file="findInclude:common/templates/footer.tpl"}
