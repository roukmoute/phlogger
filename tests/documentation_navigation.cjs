"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../docs/assets/navigation.js"), "utf8");

function navigation(current, href) {
  const linkEvents = new Map();
  const windowEvents = new Map();
  const link = {
    href,
    getAttribute: () => link.href,
    addEventListener: (name, callback) => linkEvents.set(name, callback),
  };
  const window = {
    location: { href: current },
    addEventListener: (name, callback) => windowEvents.set(name, callback),
  };
  vm.runInNewContext(source, {
    URL,
    window,
    document: { querySelectorAll: () => [link] },
  });
  return { link, window, linkEvents, windowEvents };
}
const switchTarget = (current, href) => navigation(current, href).link.href;

assert.equal(
  switchTarget("https://roukmoute.github.io/phlogger/en/?source=blog#monolog", "../fr/index.html"),
  "https://roukmoute.github.io/phlogger/fr/index.html?source=blog#monolog",
);
assert.equal(
  switchTarget("https://roukmoute.github.io/phlogger/fr/index.html?source=blog#symfony-default-logger", "../en/index.html"),
  "https://roukmoute.github.io/phlogger/en/index.html?source=blog#symfony-default-logger",
);
assert.equal(
  switchTarget("https://roukmoute.github.io/phlogger/?source=blog#rate-limits", "fr/index.html"),
  "https://roukmoute.github.io/phlogger/fr/index.html?source=blog#rate-limits",
);
assert.equal(
  switchTarget("http://localhost:8783/en/index.html?q=a%26b#context", "../fr/index.html"),
  "http://localhost:8783/fr/index.html?q=a%26b#context",
);
assert.equal(
  switchTarget("https://roukmoute.github.io/phlogger/en/#reference", "https://example.org/fr/index.html"),
  "https://example.org/fr/index.html",
);
assert.equal(
  switchTarget("https://roukmoute.github.io/phlogger/en/#reference", "../assets/navigation.js"),
  "../assets/navigation.js",
);

// A reader can choose a section after loading, then switch language.
const selectedSection = navigation("https://roukmoute.github.io/phlogger/en/", "../fr/index.html");
selectedSection.window.location.href = "https://roukmoute.github.io/phlogger/en/?source=toc#symfony-default-logger";
selectedSection.linkEvents.get("click")();
assert.equal(selectedSection.link.href, "https://roukmoute.github.io/phlogger/fr/index.html?source=toc#symfony-default-logger");

// Native link previews and opening another tab also follow later section changes.
const fragmentChange = navigation("https://roukmoute.github.io/phlogger/fr/#context", "../en/index.html");
fragmentChange.window.location.href = "https://roukmoute.github.io/phlogger/fr/#benchmarks";
fragmentChange.windowEvents.get("hashchange")();
assert.equal(fragmentChange.link.href, "https://roukmoute.github.io/phlogger/en/index.html#benchmarks");
fragmentChange.window.location.href = "https://roukmoute.github.io/phlogger/fr/#reference";
fragmentChange.linkEvents.get("auxclick")();
assert.equal(fragmentChange.link.href, "https://roukmoute.github.io/phlogger/en/index.html#reference");
console.log("Language navigation valid: 9 checks covering reciprocal section/query preservation, later section selection, local previews and rejected unrelated destinations.");
