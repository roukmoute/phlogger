"use strict";

// Both authored editions share section IDs, so switching language keeps context.
const languageLinks = document.querySelectorAll("a[data-language-switch]");
function refreshLanguageLinks() {
  const current = new URL(window.location.href);
  for (const link of languageLinks) {
    const target = new URL(link.getAttribute("href"), current);
    if (target.origin !== current.origin) continue;
    if (!/\/(en|fr)\/(?:index\.html)?$/.test(target.pathname)) continue;
    target.search = current.search;
    target.hash = current.hash;
    link.href = target.href;
  }
}

refreshLanguageLinks();
window.addEventListener("hashchange", refreshLanguageLinks);
for (const link of languageLinks) {
  link.addEventListener("click", refreshLanguageLinks);
  link.addEventListener("auxclick", refreshLanguageLinks);
}
