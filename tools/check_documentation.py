#!/usr/bin/env python3
"""Check authored language pairs and the static site's navigable contracts."""
from __future__ import annotations

from collections import Counter
from html import unescape
from html.parser import HTMLParser
from pathlib import Path
import re
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / "docs"
BASE_URL = "https://roukmoute.github.io/phlogger/"
PAIRS = (
    ("README.md", "README.fr.md"),
    ("CHANGELOG.md", "CHANGELOG.fr.md"),
    ("CONTRIBUTING.md", "CONTRIBUTING.fr.md"),
    ("benchmarks/README.md", "benchmarks/README.fr.md"),
    ("docs/frameworks.md", "docs/frameworks.fr.md"),
)
FENCES = re.compile(r"^```[^\n]*\n.*?^```", re.MULTILINE | re.DOTALL)
MD_LINKS = re.compile(r"(?<!!)\[[^\]]*\]\(([^\s)]+)\)")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def heading_ids(text: str) -> set[str]:
    """GitHub-style Unicode heading IDs, including repeated-heading suffixes."""
    seen: Counter[str] = Counter()
    result: set[str] = set()
    for heading in re.findall(r"^#{1,6} (.+?)\s*#*\s*$", FENCES.sub("", text), re.MULTILINE):
        heading = re.sub(r"<[^>]+>", "", heading)
        heading = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", heading)
        slug = "".join(char for char in unescape(heading).lower() if char.isalnum() or char in " _-").replace(" ", "-")
        suffix = f"-{seen[slug]}" if seen[slug] else ""
        seen[slug] += 1
        result.add(slug + suffix)
    return result


class Page(HTMLParser):
    def __init__(self, path: Path) -> None:
        super().__init__(convert_charrefs=True)
        self.path = path
        self.language = ""
        self.ids: list[str] = []
        self.links: list[dict[str, str | None]] = []
        self.canonical: list[str] = []
        self.alternates: dict[str, str] = {}
        self.scripts: list[str] = []
        self.headings: list[str] = []
        self.language_text: list[str] = []
        self.language_depth = 0
        self.feed(path.read_text(encoding="utf-8"))

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        if tag == "html":
            self.language = values.get("lang", "") or ""
        if "id" in values:
            self.ids.append(values["id"] or "")
        if tag == "a":
            self.links.append(values)
        if tag == "link":
            if values.get("rel") == "canonical":
                self.canonical.append(values.get("href", "") or "")
            elif values.get("rel") == "alternate":
                key = values.get("hreflang", "") or ""
                require(key not in self.alternates, f"{self.path}: duplicate hreflang {key}")
                self.alternates[key] = values.get("href", "") or ""
        if tag == "script":
            self.scripts.append(values.get("src", "") or "")
        if tag in {f"h{number}" for number in range(1, 7)}:
            self.headings.append(tag)
        if tag == "div" and values.get("class") == "languages":
            self.language_depth = 1
        elif self.language_depth:
            self.language_depth += 1

    def handle_endtag(self, tag: str) -> None:
        if self.language_depth:
            self.language_depth -= 1

    def handle_data(self, data: str) -> None:
        if self.language_depth:
            self.language_text.append(data)


def check_target(source: Path, href: str, pages: dict[Path, Page]) -> None:
    target = urlsplit(href)
    if target.scheme or target.netloc:
        if target.netloc != "github.com":
            return
        prefix = "/roukmoute/phlogger/blob/master/"
        if not target.path.startswith(prefix):
            return
        path = (ROOT / unquote(target.path.removeprefix(prefix))).resolve()
    else:
        path = (source.parent / unquote(target.path)).resolve() if target.path else source
    require(path.is_relative_to(ROOT), f"{source}: link leaves repository: {href}")
    if path.is_dir():
        path /= "index.html"
    require(path.is_file(), f"{source}: missing link target {href}")
    if not target.fragment:
        return
    fragment = unquote(target.fragment)
    if path.suffix == ".html":
        require(fragment in pages[path].ids, f"{source}: missing HTML anchor {href}")
    elif path.suffix == ".md":
        require(fragment in heading_ids(path.read_text(encoding="utf-8")), f"{source}: missing Markdown anchor {href}")


def main() -> None:
    pair_paths = {ROOT / name for pair in PAIRS for name in pair}
    ignored = {".git", "vendor", ".phpunit.cache", "phpstan-cache", "_build", ".idea", ".vscode"}
    markdown_files = {path for path in ROOT.rglob("*.md") if not (set(path.relative_to(ROOT).parts) & ignored)}
    require(markdown_files == pair_paths, "Every project Markdown document must have an explicit English/French pair")
    fence_count = 0
    for english, french in PAIRS:
        en = (ROOT / english).read_text(encoding="utf-8")
        fr = (ROOT / french).read_text(encoding="utf-8")
        en_blocks, fr_blocks = FENCES.findall(en), FENCES.findall(fr)
        require(en_blocks == fr_blocks, f"{english}: translated edition changed code examples")
        fence_count += len(en_blocks)
        levels = lambda text: re.findall(r"^(#{1,6}) ", FENCES.sub("", text), re.MULTILINE)
        require(levels(en) == levels(fr), f"{english}: translated section structure differs")
        require(f"**English** · [Français]({Path(french).name})" in en, f"{english}: missing French language choice")
        require(f"[English]({Path(english).name}) · **Français**" in fr, f"{french}: missing English language choice")
        if english == "CHANGELOG.md":
            require(re.findall(r"^## (.+)$", en, re.MULTILINE) == re.findall(r"^## (.+)$", fr, re.MULTILINE), "Release chronology differs between languages")

    pages = {path.resolve(): Page(path) for path in SITE.rglob("*.html")}
    expected_alternates = {"en": BASE_URL + "en/", "fr": BASE_URL + "fr/", "x-default": BASE_URL}
    for path, page in pages.items():
        require(len(page.ids) == len(set(page.ids)), f"{path}: duplicate HTML ID")
        relative = path.relative_to(SITE)
        locale = relative.parts[0] if len(relative.parts) > 1 else "en"
        require(page.language == locale, f"{path}: incorrect HTML language")
        canonical = BASE_URL if len(relative.parts) == 1 else BASE_URL + locale + "/"
        require(page.canonical == [canonical], f"{path}: incorrect canonical URL")
        require(page.alternates == expected_alternates, f"{path}: missing reciprocal language metadata")
        require(page.scripts == ["assets/navigation.js" if len(relative.parts) == 1 else "../assets/navigation.js"], f"{path}: missing local language navigation")
        for script in page.scripts:
            check_target(path, script, pages)
        for link in page.links:
            check_target(path, link.get("href", "") or "", pages)
            if "data-language-switch" in link:
                require(link.get("hreflang") in ("en", "fr"), f"{path}: language link has no locale")
        if len(relative.parts) > 1:
            labels = " ".join(page.language_text)
            require("English" in labels and "Français" in labels, f"{path}: language names must be explicit")
            for original, translated in PAIRS:
                if not original.startswith(("docs/", "benchmarks/")):
                    continue
                expected = translated if locale == "fr" else original
                require(any((link.get("href", "") or "").endswith(expected) for link in page.links), f"{path}: missing {locale} guide {expected}")

    en_page = pages[(SITE / "en/index.html").resolve()]
    fr_page = pages[(SITE / "fr/index.html").resolve()]
    require(en_page.ids == fr_page.ids, "Website section IDs must match when switching languages")
    require(en_page.headings == fr_page.headings, "Website heading structure differs between languages")
    pre_blocks = lambda path: re.findall(r"<pre><code>(.*?)</code></pre>", path.read_text(encoding="utf-8"), re.DOTALL)
    require(pre_blocks(en_page.path) == pre_blocks(fr_page.path), "Website translation changed code examples")
    for path in pair_paths:
        for href in MD_LINKS.findall(FENCES.sub("", path.read_text(encoding="utf-8"))):
            check_target(path, href, pages)
    print(f"Documentation valid: {len(PAIRS)} Markdown pairs, {fence_count} identical fenced examples, {len(pages)} HTML pages, {len(pre_blocks(en_page.path))} identical website examples.")


if __name__ == "__main__":
    main()
