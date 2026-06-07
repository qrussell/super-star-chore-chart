=== Super Star Chore Chart ===
Contributors: quentinrussell
Tags: chore chart, kids, family, tasks, parenting
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A family-based, server-synced interactive chore chart for kids with multi-device real-time updates.

== Description ==

Super Star Chore Chart v2.0 lets families manage weekly chores together from any device.

**Family System**
* Logged-in WordPress users create a family with a name and password
* Other family members join using the family name + password
* All members share the same chart — changes sync automatically via server-side polling

**Chore Chart Features**
* Per-kid tabs — add, rename, or remove kids
* Paid and unpaid task categories
* Daily checkboxes (Mon–Sun) with per-task totals
* Weekly earnings summary per kid
* Edit Mode — rename tasks, adjust pay rates, toggle paid/unpaid
* Default Templates — save a master task list to reuse each week
* Weekly Archives — snapshot completed weeks for review
* Print Mode — clean black-and-white printout for the active kid

**Multi-Device Sync**
* Server-side data storage in WordPress database
* Configurable polling interval (default 15 seconds)
* Any family member's changes are visible to all others within seconds

== Installation ==

1. Upload `super-star-chore-chart.zip` via **Plugins → Add New → Upload Plugin**
2. Click **Install Now** then **Activate**
3. A **Chore Chart** page is automatically created at `/chore-chart/`
4. Navigate to **Settings → Chore Chart** to configure options
5. Use the shortcode `[chore_chart]` on any page or post

== Frequently Asked Questions ==

= Do users need a WordPress account? =
Yes. Each family member must be registered and logged in to access the chart.

= Can a user be in more than one family? =
No — each user belongs to one family at a time. They can leave and join another.

= How do I increase the max number of family members? =
Go to **Settings → Chore Chart** and raise the "Max members per family" value.

= Is the chart data secure? =
Yes. All AJAX requests use WordPress nonce verification. Family passwords are hashed using wp_hash_password().

== Changelog ==

= 2.0.0 =
* Complete rewrite with family-based multi-user system
* Server-side data storage in custom database tables
* Real-time polling for multi-device sync
* AJAX-based save for all chart mutations
* Family create/join/leave flow

= 1.0.0 =
* Initial release — single-user, localStorage-based chart
* Multi-kid tabs, paid/unpaid tasks, archives, defaults, print

== Upgrade Notice ==

= 2.0.0 =
Major update. Requires user accounts. Data from v1 localStorage is not migrated automatically.
