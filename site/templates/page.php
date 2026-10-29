<?php
$base = rtrim(getenv('BASE_URL') ?: '', '/');
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="smgen-base-url" content="<?=$escape($base);?>">
<script>
try {
  const savedTheme = localStorage.getItem('phonics-doc-theme');
  const theme = savedTheme === 'light' || savedTheme === 'dark'
    ? savedTheme
    : (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

  if(savedTheme === 'light' || savedTheme === 'dark') document.documentElement.dataset.theme = savedTheme;
  document.documentElement.classList.add(theme);
} catch {}
</script>
<title>$if(display-title)$$display-title$$else$$title$$endif$ · Phonics documentation</title>
$if(description-meta)$<meta name="description" content="$description-meta$">$endif$
<?php renderJsonLd($frontmatter, $pageFile); ?>
<link rel="icon" href="data:,">
<link rel="sitemap" href="<?=$escape(resolveAssetUrl('/sitemap.xml'));?>">
<?php if(llmsEnabled()): ?>
<link rel="describedby" type="text/markdown" href="<?=$escape(resolveAssetUrl('/llms.txt'));?>">
<?php endif; ?>
$for(css)$<link rel="stylesheet" href="$css$">$endfor$
<style>$styles.html()$</style>
<?php if(getConf('SCRIPTS')) foreach(getConf('SCRIPTS') as $javascript): ?>
<script src="<?=$escape(resolveAssetUrl($javascript));?>"></script>
<?php endforeach; ?>
<script defer src="<?=$escape($base);?>/site.js"></script>
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<header class="masthead">
  <a class="brand" href="<?=$escape($base);?>/index.html"><span class="brand-mark" aria-hidden="true">p/</span>phonics<span class="brand-label">docs</span></a>
  <div class="masthead-actions">
    <nav aria-label="Primary"><?php renderMenuRegion('header'); ?><a class="masthead-source" href="https://github.com/seanmorris/php-wasm-engine" target="_blank" rel="noopener noreferrer">GitHub <span aria-hidden="true">↗</span></a></nav>
    <?php if((string) getConf('SEARCH_ENABLED') === '1'): ?>
    <div class="site-search" data-site-search>
      <button class="search-toggle" type="button" data-search-toggle aria-label="Search documentation" title="Search documentation" aria-expanded="false" aria-controls="site-search-panel">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
      </button>
      <div class="search-panel" id="site-search-panel" data-search-panel aria-hidden="true">
        <label class="visually-hidden" for="search-query">Search documentation</label>
        <input id="search-query" type="search" placeholder="Search documentation" autocomplete="off" aria-controls="search-results" data-search-index="<?=$escape(resolveAssetUrl('/search.bin'));?>" data-search-reader="<?=$escape(resolveAssetUrl(getConf('SEARCH_READER')));?>" data-search-results="#search-results">
        <ul class="search-results" id="search-results" aria-live="polite"></ul>
      </div>
    </div>
    <?php endif; ?>
    <button class="theme-toggle" type="button" data-theme-toggle hidden aria-label="Use dark theme" title="Use dark theme">
      <svg data-theme-icon="dark" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
      <svg data-theme-icon="light" viewBox="0 0 24 24" aria-hidden="true" hidden><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
    </button>
  </div>
</header>
<div class="layout">
  <aside class="sidebar">
    <nav class="site-navigation" aria-label="Documentation"><?php renderMenuRegion('sidebar'); ?></nav>
    <div class="sidebar-note"><b>World-first games.</b><br>Built with PHP. Assembled in Tiled.</div>
  </aside>
  <main id="content" tabindex="-1">
    $if(api-page)$
    <header class="page-header api-page-header">
      <?php renderBreadcrumbs(['home' => 'Documentation']); ?>
      <h1>$if(display-title)$$display-title$$else$$title$$endif$</h1>
      $if(description-meta)$<p class="subtitle">$description-meta$</p>$endif$
      $if(source-url)$<a class="api-source" href="$source-url$" target="_blank" rel="noopener noreferrer">View source <span aria-hidden="true">↗</span></a>$endif$
    </header>
    $else$
    <?php renderBreadcrumbs(['home' => 'Documentation']); ?>
    <h1>$if(display-title)$$display-title$$else$$title$$endif$</h1>
    $if(description-meta)$<p class="subtitle">$description-meta$</p>$endif$
    $endif$
    <article>$body$</article>
    <footer><a href="<?=$escape($base);?>/contributing.html">Contribute to Phonics</a><span>PHP + Pandoc + <a href="https://github.com/seanmorris/smgen">smgen</a> + <a href="https://github.com/seanmorris/smgen-assistant">smgen-assistant</a></span><a class="sitemap-link" href="<?=$escape($base);?>/sitemap.xml"><img src="<?=$escape($base);?>/sitemap-badge.png" width="80" height="15" alt="XML sitemap"></a></footer>
  </main>
  $if(toc)$<aside class="contents"><details open class="page-navigation"><summary>On this page</summary><nav aria-label="On this page">$toc$<a class="back-top" href="#content">Back to top ↑</a></nav></details></aside>$endif$
</div>
<?php if(getConf('BODY_SCRIPTS')) foreach(getConf('BODY_SCRIPTS') as $javascript): ?>
<script src="<?=$escape(resolveAssetUrl($javascript));?>"></script>
<?php endforeach; ?>
</body>
</html>
