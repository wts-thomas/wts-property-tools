== Changelog ==

2.4.0 - 10-10-2025
- Revised Batch functions to use AJAX

2.3.6 - 10-09-2025
- Optimzed Batch Draft and Batch Delete functions.

2.3.5 - 10-09-2025
- Decreased amount of properties deleted.
- Added a Stop button for Auto batches.

2.3.4 - 10-09-2025
- Adjusted Deleted counts to mitigate server timeouts.

2.3.3 - 10-09-2025
- Batch UI Adjustments and Testing.

2.3.2 - 10-09-2025
- Cleaned up the Draft page UI.

2.3.1 - 10-09-2025
- Adjustment to batch quantity to avoid server timeouts.
- Added batch options to delete drafted properties.
- Increased timeout from 800 to 2000 (2-seconds).

2.3.0 - 10-09-2025
- Draft expired-withdrawn properties in batches. 

2.2.2 - 10-09-2025
- Added Site Title to email notifications subject line.

2.2.1 - 10-09-2025
- Updated Version Checker to ensure propert fectching of updates.

2.2.0 - 10-09-2025
- Reordered the Tables to show Match first.
- Adjustments to the Cron email template.

2.1.1 - 10-08-2025
- Added 'Contingent' to the draft terms list

2.1.0 - 09-17-2025
- Compares subdivision from site to subdivision imported from MLS.
- Displays N/A if subdivision names don't match.

2.0.0 - 09-17-2025
- Compares builders from site to builders imported from MLS.
- Displays N/A if builder names don't match.

1.0.0 - 09-12-2025
- Digest email notifications when posts or properties are created or updated.
- Digest includes Address, Builder, Community, Subdivision, Type, and Action in a clean HTML table format.
- Supports manual posts, imported posts, and cron-detected posts.
- Newly published properties posts are automatically reverted to Draft until approved.
- Prevents accidental publishing of unreviewed properties.
- Button in the WordPress Admin to change all properties with Expired or Withdrawn status to Draft.
- Secondary button to delete those expired/withdrawn draft properties when needed.
- Detects orphaned property images in the Media Library (images not linked to any property).
- Admin interface displays orphaned images with thumbnails, IDs, and metadata.
- Option to bulk delete orphaned images directly from the admin.
- WP-Cron job checks every 15 minutes for new property posts created via imports or direct DB inserts.
- Admin button to manually trigger the notification check (useful for local/dev environments).
- Organized into modular includes (notifications, auto-draft, orphaned-media, etc.).