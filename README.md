# KolzchutReports extension for MediaWiki

NOTE: this is a custom extension for Kol-Zchut (kolzchut.org.il).
      It was not designed with public use in mind.


This extension adds special report pages for stuff we need, such as "how many pages were updated since X".
While this exists in some form in MediaWiki already, it doesn't take into account our specific requirements and filters.

## Requirements
- MediaWiki 1.43+

## Usage
Special pages:
- Special: ArticlesUpdatedReport

## Todo


## Changelog
### 0.2.2
Modernize to use service injection, namespaced classes under `MediaWiki\Extension\KolzchutReports`,
`AutoloadNamespaces`, and `SelectQueryBuilder` for all database queries. Remove `wfGetDB()`,
`MediaWikiServices::getInstance()`, and `ActorMigration`. Rename extension to KolzchutReports.
Requires MediaWiki 1.42+.

### 0.2.1 [2026-02-10]
- **Added ignore users feature**: New multiselect field to temporarily exclude specific users from report
  - Uses built-in `HTMLUsersMultiselectField` with username autocomplete
  - Works alongside automatic bot exclusion
  - Excluded users are shown in debug mode
  - Fully internationalized (English & Hebrew)

### 0.2.0 [2025-02-09]
Major improvements to ArticlesUpdatedReport:
- **Fixed critical bug**: Bot filtering now works correctly
- **Fixed SQL injection vulnerability** in category filter using proper DB escaping
- **Optimized query performance** using `COUNT(DISTINCT rev_page)` instead of fetching all rows
- **Added debug mode**: Optional checkbox to display editor details with bot exclusion verification
- **Improved UX**: Collapsible form that persists after submission for easy parameter adjustments

### 0.1.1 [2021-06-24]
Attempt to fix ArticlesUpdatedReport for MediaWiki 1.35, by using ActorMigration & CommentStore for queries

### 0.1.0 [2020-11-01]
initial version
