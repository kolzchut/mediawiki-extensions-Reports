# WikiRights Report extension for MediaWiki

NOTE: this is a custom extension for Kol-Zchut (kolzchut.org.il).
      It was not designed with public use in mind.


This extension adds special report pages for stuff we need, such as "how many pages were updated since X".
While this exists in some form in MediaWiki already, it doesn't take into account our specific requirements and filters.

## Usage
Special pages:
- Special: ArticlesUpdatedReport

## Todo


## Changelog
### 0.2.0 [2025-02-09]
Major improvements to ArticlesUpdatedReport:
- **Fixed critical bug**: Bot filtering now works correctly by selecting `actor_user` instead of `actor_id` in subquery
- **Fixed SQL injection vulnerability** in category filter using proper DB escaping
- **Fixed actor migration compatibility** for MediaWiki 1.35 by using dynamic field names
- **Optimized query performance** using `COUNT(DISTINCT rev_page)` instead of fetching all rows
- **Added debug mode**: Optional checkbox to display editor details with bot exclusion verification
- **Improved UX**: Collapsible form that persists after submission for easy parameter adjustments

### 0.1.1 [2021-06-24]
Attempt to fix ArticlesUpdatedReport for MediaWiki 1.35, by using ActorMigration & CommentStore for queries

### 0.1.0 [2020-11-01]
initial version
