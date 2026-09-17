/*
 * Chapter 31 — Users, Roles & Permissions
 * The Team list, inviting a staff user (form filled, Send Invitation only hovered — the
 * API always emails an invite), the five roles and the role-default permission matrix,
 * a user's profile and per-user overrides (reason modal opened then cancelled), when
 * permission changes take effect, the super-admin Lockout tab (hover only — nobody is
 * locked), MFA requirements, and the Audit Log.
 *
 * Creates / changes NO records: there are no commit clicks in this chapter.
 * Demo users: Frank Dispatcher (id 9, no existing overrides) for the per-user matrix.
 *
 * Narration facts verified in code:
 *  - Users module is super_admin only (app/admin/users/*.php access wall).
 *  - Invite = status 'invited' + emailed link valid 7 days (api/v1/users/create.php).
 *  - can(): super_admin always allowed → per-user override → role override → config default.
 *  - Override/role-default edits stamp users.permissions_updated_at, and
 *    _ff_check_permission_freshness() reloads them on the user's NEXT PAGE LOAD; a role
 *    change or suspend/lock ends the open session (re-login). Built-in defaults load at login.
 *  - Lockout: blocks login + password reset, ends open session, reason required, unlock = Activate.
 *  - security.mfa.required_roles save reconciles users.mfa_required → forced MFA setup at next
 *    login; it never removes an authenticator someone already enrolled.
 */
const tab = (name) => `button.tab-btn:has-text("${name}")`;
const matrixToggle = (label, n = 0) => `.perm-matrix-row:has(.perm-matrix-row-label:text-is("${label}")) .perm-ios-toggle >> nth=${n}`;

export default {
  title: 'Users, Roles & Permissions',
  subtitle: 'Who can log in, what each person is allowed to do, and how to take access away fast.',
  start: '/dashboard',
  intro: 'In this chapter we will manage staff accounts: inviting a user, choosing their role, fine-tuning permissions, locking an account out, requiring two-factor sign-in, and checking the audit log.',
  outro: 'That covers users and permissions. Next, we will walk through the rest of Settings.',
  scenes: [
    {
      say: 'Open Users from the Admin section of the sidebar. This module is reserved for super admins; anyone else who opens it sees an access restricted page.',
      run: async (d) => { await d.nav('Users', '/users'); },
    },
    {
      say: 'The tiles count your whole team, who is active and able to log in, invitations still waiting to be accepted, and how many active users have two-factor sign-in turned on.',
      run: async (d) => { await d.highlight('.stat-grid', 'Team at a glance', 3200); },
    },
    {
      say: 'Each row shows the person, their email, role, account status, whether two-factor is on, and when they last logged in. Invited users who have not accepted yet appear in italics.',
      run: async (d) => { await d.highlight('table thead', 'Team list', 2400); await d.scroll(500); await d.scroll(-500); },
    },
    {
      say: 'Search by name or email, or narrow the list by role and status. Here we show only dispatchers.',
      run: async (d) => {
        await d.select('[x-model="filters.role_id"]', { label: 'Dispatcher' });
        await d.wait(1400);
        await d.hover('[x-model="filters.status"]', 700);
        await d.click('button:has-text("Reset")');
        await d.wait(800);
      },
    },
    {
      say: 'To give someone access, click Invite New User.',
      run: async (d) => { await d.click('a:has-text("Invite New User")', { nav: true }); },
    },
    {
      say: 'Enter their full name and work email, then pick a role. The role decides what they can see and do from their very first login.',
      run: async (d) => {
        await d.type('#user-name', 'Jordan Mitchell');
        await d.type('#user-email', 'jordan.mitchell@mainlandtts.ca', { delay: 22 });
        await d.select('#user-role', { label: 'Dispatcher' });
      },
    },
    {
      say: 'Phone and time zone are optional.',
      run: async (d) => {
        await d.type('#user-phone', '604-555-0188');
        await d.type('#user-timezone', 'America/Vancouver', { delay: 25 });
      },
    },
    {
      say: 'Send Invitation creates the account as Invited and emails a link that is valid for seven days. The person sets their own password; you never need to know it. We will not send one here.',
      run: async (d) => { await d.highlight('button:has-text("Send Invitation")', 'Emails the invite link', 3200); },
    },
    {
      say: 'Now, roles. Go back to Users and open the Default Permissions tab.',
      run: async (d) => {
        await d.click('a:has-text("Cancel")', { nav: true });
        await d.click(tab('Default Permissions'));
        await d.wait(700);
      },
    },
    {
      say: 'There are five roles. Super Admin has full access to everything and cannot be restricted, so it is not listed here. Manager has broad operational access but no user management or system settings.',
      run: async (d) => { await d.highlight('.perm-role-cards-row', 'Built-in roles', 3600); },
    },
    {
      say: 'Dispatcher covers customers, equipment, leases, reservations and maintenance, with no financial data. Accountant has full financial access. Read Only can look but not change anything.',
      run: async (d) => {
        await d.hover('.perm-role-card:has-text("Dispatcher")', 1600);
        await d.hover('.perm-role-card:has-text("Accountant")', 1600);
        await d.hover('.perm-role-card:has-text("Read Only")', 1400);
      },
    },
    {
      say: "Click a role to see its permission matrix. Each module has switches for view, create, edit, delete and export, and some add their own, such as post or approve. Green means that role has it.",
      run: async (d) => {
        await d.click('.perm-role-card:has-text("Dispatcher")');
        await d.wait(1500);
        await d.highlight('.perm-matrix-card .perm-section >> nth=0', 'Dispatcher defaults', 2800);
      },
    },
    {
      say: 'Changing a switch here changes it for every user with that role, and asks you for a reason. Changed switches are listed under Active Overrides, and Reset all puts the role back to its standard defaults.',
      run: async (d) => {
        await d.hover(matrixToggle('Customers', 2), 1200);
        await d.highlight('.perm-sidebar-card:has-text("Active Overrides")', 'Role-wide overrides', 2600);
      },
    },
    {
      say: 'Permissions are grouped: fleet operations, maintenance and compliance, financial, analytics and reports, system settings, and QuickBooks.',
      run: async (d) => { await d.scroll(1400); await d.wait(900); await d.scroll(1400); await d.wait(700); await d.scroll(-2800); },
    },
    {
      say: "Sometimes one person needs an exception. Back on the Team tab, open that user's profile. We'll open Frank Dispatcher.",
      run: async (d) => {
        await d.click(tab('Team'));
        await d.type('[x-model="filters.q"]', 'Frank');
        await d.wait(1300);
        await d.click('table tbody tr:has-text("Frank Dispatcher") a:has-text("View")', { nav: true });
      },
    },
    {
      say: 'The profile shows their details and login history. Edit changes their name, email, phone, time zone or role.',
      run: async (d) => {
        await d.highlight('.card:has-text("User Details")', 'User details', 2200);
        await d.hover('#btn-edit', 900);
      },
    },
    {
      say: 'On the right: send a password reset email, set a password directly, or change their status. Deactivate and Suspend both stop the account from logging in; you can activate it again later.',
      run: async (d) => {
        await d.hover('#btn-send-reset', 1000);
        await d.hover('.card:has-text("Change Status") button:has-text("Deactivate")', 900);
        await d.hover('.card:has-text("Change Status") button:has-text("Suspend")', 900);
      },
    },
    {
      say: 'Lock Out is the emergency option: it needs a reason, blocks login and password reset, and ends any session they have open. We will look at it again on the Lockout tab.',
      run: async (d) => { await d.highlight('.card:has-text("Change Status") button:has-text("Lock Out")', 'Emergency lockout — not clicked', 3000); },
    },
    {
      say: 'Click Manage Permissions to fine-tune this one person on top of their role.',
      run: async (d) => { await d.click('a:has-text("Manage Permissions")', { nav: true }); },
    },
    {
      say: 'The role cards at the top set their baseline. Choosing a different role asks you to confirm, and signs the person out so they log back in with the new role.',
      run: async (d) => { await d.highlight('.perm-role-cards-row', 'Role assignment', 3000); },
    },
    {
      say: "Below is this user's own matrix. Dispatchers can't see reports by default, so clicking that switch offers to grant it, and a reason is required for the audit trail.",
      run: async (d) => {
        await d.click(matrixToggle('Reports'));
        await d.wait(700);
        await d.type('.modal textarea[x-model="reasonModal.reason"]', 'Covering month-end reporting while the office manager is away.', { delay: 20 });
      },
    },
    {
      say: 'Grant would save it straight away. An orange ring marks an allow override, red marks a deny. We will cancel instead.',
      run: async (d) => {
        await d.hover('.modal button:has-text("Grant")', 1400);
        await d.click('.modal button:has-text("Cancel")');
        await d.wait(600);
      },
    },
    {
      say: 'Section buttons apply a whole group at once: view only, read and write, deny all, or clear. The sidebar lists every override and lets you pick which notification types this person receives.',
      run: async (d) => {
        await d.hover('.perm-section-bulk-actions button:has-text("Read+Write")', 1100);
        await d.highlight('.perm-sidebar-col', 'Overrides and notifications', 2600);
      },
    },
    {
      say: "When do changes apply? Overrides and role defaults take effect on the user's next page load, with no need to log out. A role change, suspension or lockout ends their session, so they must sign in again.",
      run: async (d) => { await d.highlight('.perm-matrix-card-header', 'Applies on next page load', 3800); },
    },
    {
      say: 'Now the Lockout tab. Open Settings and choose Lockout. Only super admins can see this tab at all.',
      run: async (d) => {
        await d.nav('Settings', '/settings');
        await d.click(tab('Lockout'));
        await d.wait(700);
      },
    },
    {
      say: 'Use it when access must stop right now, for example when a contract ends. Locking blocks login, blocks password resets so they cannot get back in on their own, and ends any open session the next time they load a page.',
      run: async (d) => { await d.highlight('.card:has-text("User Lockout") >> nth=0', 'Break-glass control', 4200); },
    },
    {
      say: 'Every user has a Lock Out button, and a reason is always required. Locked accounts show who locked them and why, and an Unlock button restores access. We are not locking anyone here.',
      run: async (d) => {
        await d.hover('tr:has-text("Frank Dispatcher") button:has-text("Lock Out")', 1600);
        await d.highlight('table thead', 'Status and lock details', 2200);
      },
    },
    {
      say: 'Two-factor sign-in is set on the Integrations tab, in the Security and M F A card. Tick the roles that must use an authenticator app.',
      caption: 'Two-factor sign-in is set on the Integrations tab, in the Security / MFA card. Tick the roles that must use an authenticator app.',
      run: async (d) => {
        await d.click(tab('Integrations'));
        await d.wait(700);
        await d.highlight('.card:has(.card-header:has-text("Security / MFA"))', 'MFA requirements', 2600);
      },
    },
    {
      say: 'After you save, anyone in a ticked role who has not enrolled is sent through two-factor setup at their next login. Unticking a role never removes an authenticator someone already set up.',
      run: async (d) => {
        await d.hover('[name="security.mfa.required_roles[]"] >> nth=1', 900);
        await d.hover('button:has-text("Save Security / MFA Settings")', 1400);
      },
    },
    {
      say: 'Anyone can turn two-factor on for themselves from My Profile. If someone loses their phone and backup codes, a super admin can disable it from that user’s profile page.',
      run: async (d) => {
        await d.goto('/profile');
        await d.highlight('a:has-text("Set Up Two-Factor Authentication")', 'Self-service MFA setup', 2600);
      },
    },
    {
      say: 'Finally, the Audit Log in the sidebar. It is a read-only record of who created, changed, deleted or logged in, with the date, module, record and IP address.',
      run: async (d) => {
        await d.nav('Audit Log', '/audit');
        await d.highlight('table thead', 'Every change, who made it, and when', 2600);
      },
    },
    {
      say: 'Filter by module, user, action or date range. Here we show only changes to customer records.',
      run: async (d) => {
        await d.select('select[name="module"]', 'customers');
        await d.click('button:has-text("Filter")', { nav: true });
        await d.wait(1200);
      },
    },
  ],
};
