/*
 * Chapter 15 — Samsara Tracking
 * The Fleet Tracking page (map, list, unlinked tabs, alerts strip), a linked unit's Samsara
 * Mapping tab (location + odometer telemetry), and how GPS readings reach lease mileage
 * billing — only when the lease's Mileage Tracking mode is "Samsara".
 *
 * Read-only chapter: creates and changes nothing. Refresh, Sync All Now, Import from Samsara,
 * Sync Now, Unlink and Track in Samsara are only HOVERED (Sync/Import/Sync Now call the live
 * Samsara API). Expanding the alerts strip and switching tabs is local UI only.
 *
 * Dev-data note: demo units are linked but have no GPS fix, so the map has no pins and most
 * telemetry cells show dashes — narration says so rather than pretending otherwise.
 */
// The odometer card is the last thing on the lease page, so it can't scroll above the caption
// pill; give the page some bottom room first (cosmetic, in-page only).
const padBottom = (d) => d.page.evaluate(() => { const m = document.querySelector('main') || document.body; m.style.paddingBottom = '420px'; });

export default {
  title: 'Samsara Tracking',
  subtitle: 'Where every tracked unit is, what its odometer reads, and how GPS distance feeds mileage billing.',
  start: '/dashboard',
  intro: 'In this chapter we will look at Samsara tracking: the fleet map and telemetry list, a unit’s tracking tab, and how G P S readings flow into lease mileage.',
  outro: 'That covers Samsara Tracking. The key rule: G P S distance only bills on leases whose mileage tracking is set to Samsara.',
  scenes: [
    {
      say: 'Open Samsara Tracking from the sidebar. The header counts units linked to a Samsara device, and units that are not linked yet.',
      run: async (d) => {
        await d.nav('Samsara Tracking', '/tracking');
        await d.wait(1500);
        await d.highlight('h1.page-header-title', 'Linked and unlinked', 2400);
      },
    },
    {
      say: 'A background job pulls fresh telemetry from Samsara every five minutes. This page only reads those saved readings, so it loads instantly.',
      run: async (d) => { await d.highlight('.page-header p', 'Synced every 5 minutes', 2600); },
    },
    {
      say: 'Refresh re-reads the saved data, and Auto-refresh does that every minute. Sync All Now asks Samsara for new readings right away, and Import from Samsara creates any trackers not yet in the system. Use those two sparingly.',
      run: async (d) => {
        await d.hover('button:has-text("Refresh")', 1000);
        await d.hover('label:has-text("Auto-refresh")', 900);
        await d.hover('button:has-text("Sync All Now")', 1300);
        await d.hover('button:has-text("Import from Samsara")', 1300);
      },
    },
    {
      say: 'Active Alerts flags problems: a low or critical battery, a unit that has not connected in eight or twenty-four hours, or one with no G P S fix.',
      caption: 'Active Alerts flags problems: a low or critical battery, a unit that has not connected in 8 or 24 hours, or one with no GPS fix.',
      run: async (d) => {
        if (await d.exists('text=Active Alerts', 3000)) {
          await d.click('text=Active Alerts');
          await d.wait(1500);
          await d.scroll(300);
        }
      },
    },
    {
      say: 'Dismiss hides an alert for twenty-four hours, in your browser only. It does not fix anything, so follow up on the unit first.',
      caption: 'Dismiss hides an alert for 24 hours, in your browser only. It does not fix anything, so follow up on the unit first.',
      run: async (d) => {
        if (await d.exists('button:has-text("Dismiss all")', 2000)) {
          await d.hover('button:has-text("Dismiss")', 900);
          await d.scroll(-300);
          await d.click('text=Active Alerts');
        }
      },
    },
    {
      say: 'Map View pins every unit with a G P S fix, coloured by status. Search the list on the left, and click a unit to zoom to it. In this training copy no units have reported a location yet, so the map is empty.',
      caption: 'Map View pins every unit with a GPS fix, coloured by status. Search the list on the left, and click a unit to zoom to it. In this training copy no units have reported a location yet.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Map View")');
        await d.wait(1200);
        await d.highlight('#tracking-map', 'Fleet map', 2400);
        await d.hover('input[placeholder="Search units…"]', 800);
      },
    },
    {
      say: 'A unit counts as online when it has a location and has connected in the last eight hours. Anything else is offline.',
      caption: 'A unit counts as online when it has a location and has connected in the last 8 hours. Anything else is offline.',
      run: async (d) => { await d.highlight('.badge:has-text("Online")', 'Online / offline', 2400); },
    },
    {
      say: 'List View shows every linked unit with its status, current customer, battery, odometer, speed, last location, and when it last connected and synced.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("List View")');
        await d.wait(900);
        await d.highlight('table.data-table thead', 'Telemetry', 2600);
      },
    },
    {
      say: "Search by unit, type, location or customer. Let's find the Volvo tractors, which report an odometer from the truck itself.",
      run: async (d) => {
        await d.type('input[x-model="listSearch"]', 'Volvo', { delay: 60 });
        await d.wait(1000);
        await d.highlight('table.data-table tbody', 'Odometer readings', 2200);
      },
    },
    {
      say: 'The Unlinked tab lists units with no Samsara device. Link to Samsara opens that unit’s tracking tab, where you pick its vehicle or trailer from Samsara.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Unlinked")');
        await d.wait(900);
        await d.hover('a:has-text("Link to Samsara")', 1800);
      },
    },
    {
      say: 'Back in List View, click a unit to open its profile. We will open tractor T R seven zero one zero.',
      caption: 'Back in List View, click a unit to open its profile. We will open tractor TR-7010.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("List View")');
        await d.wait(700);
        await d.click('table.data-table tbody a:has-text("TR-7010")', { nav: true });
      },
    },
    {
      say: 'Open the Samsara Mapping tab. It shows which Samsara vehicle or trailer this unit is linked to, when it last synced, and its current location on a map.',
      run: async (d) => {
        await d.click('button:has-text("Samsara Mapping")');
        await d.wait(1500);
        await d.highlight('.card:has-text("Linked to")', 'Samsara link', 2400);
      },
    },
    {
      say: 'Live Telemetry lists the address, coordinates, speed, odometer, battery and last connection. Samsara supplies location and odometer; engine or reefer hours are not read from Samsara and are entered by staff.',
      run: async (d) => {
        await d.scroll(500);
        await d.highlight('.card:has(.card-title:has-text("Live Telemetry"))', 'Live telemetry', 3000);
      },
    },
    {
      say: 'Sync Now pulls this one unit from Samsara immediately. Unlink removes the connection. Neither is needed day to day, and we will not press them here.',
      run: async (d) => {
        await d.scroll(-500);
        await d.hover('button:has-text("Sync Now")', 1300);
        await d.hover('button:has-text("Unlink")', 1300);
      },
    },
    {
      say: 'Now, how G P S reaches billing. Every lease has a Mileage Tracking setting: Samsara, Manual, or Off. Here is a Samsara lease for Rocky Mountain Freight.',
      caption: 'Now, how GPS reaches billing. Every lease has a Mileage Tracking setting: Samsara, Manual, or Off. Here is a Samsara lease for Rocky Mountain Freight.',
      run: async (d) => {
        await d.nav('Leases', '/leases');
        await d.type('[x-model="filters.search"]', 'CN-DEMO-3728B6', { delay: 50 });
        await d.wait(1500);
        await d.click('table tbody tr:has-text("CN-DEMO-3728B6") a[href*="leases/show"]', { nav: true });
        await d.highlight('.ff-rate-cell:has-text("Mileage tracking")', 'Mileage tracking mode', 2400);
      },
    },
    {
      say: 'On a Samsara lease, the starting odometer is captured from Samsara when the lease is activated. When an invoice is generated for a period that has ended, the system asks Samsara how far the unit travelled and bills that distance at the lease’s mileage rate.',
      run: async (d) => {
        await d.hover('text=Per-unit rate', 1400);
        await padBottom(d);
        await d.scroll(1400);
        await d.highlight('#mileage-tracking-card', 'Odometer and distance', 2600);
      },
    },
    {
      say: 'At close, Fetch from Samsara fills in the closing odometer, and you can still type over it. A daily job also saves a G P S reading to Mileage Logs for each active Samsara lease.',
      caption: 'At close, Fetch from Samsara fills in the closing odometer, and you can still type over it. A daily job also saves a GPS reading to Mileage Logs for each active Samsara lease.',
      run: async (d) => {
        await d.scroll(-1400);
        await d.hover('button:has-text("Close Lease")', 2000);
      },
    },
    {
      say: 'On a Manual lease, Samsara is never used, even if the unit is linked; staff enter the readings. On an Off lease, no mileage is billed at all, even if a rate is set, so check this setting when you create the lease.',
      run: async (d) => {
        await d.hover('button:has-text("Edit Lease"), a:has-text("Edit Lease")', 2400);
      },
    },
  ],
};
