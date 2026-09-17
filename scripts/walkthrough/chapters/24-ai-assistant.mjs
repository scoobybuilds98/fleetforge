/*
 * Chapter 24 — AI Assistant
 * The full-page assistant at /ai (chat, history, Reports, Documents, Alerts, Usage),
 * the floating assistant available on every page, AI Analysis on a record
 * (Summit Carriers Ltd.), and how change proposals (Apply / Cancel / Undo) work.
 *
 * AI CALLS: exactly two real questions are asked on camera — one on the full page
 * (POST /api/v1/ai/stream) and one in the floating widget (POST /api/v1/ai/chat).
 * They only fire in a real recording, or in a dry run with WT_AI_LIVE=1. A plain
 * `--dry` types the questions but never sends them, so selector iteration costs nothing.
 * Creates: two AI chat sessions for the recorder user (visible under Recent).
 * Never clicked: Generate Now, Run Scan, Analyze, Generate (hovered and narrated).
 */
const LIVE = !process.argv.includes('--dry') || process.env.WT_AI_LIVE === '1';
const Q1 = 'Which customers have overdue invoices over $5,000?';
const Q2 = 'How many units are on lease, available, and in maintenance right now?';
const aiTab = (name) => `button:text-is("${name}")`;
// Inline helper: smooth-scroll an inner scroller programmatically. Wheel events chain to the
// document once the chat list hits its top, which shifts the whole page on camera.
const glide = async (d, sel, where, ms = 1400) => {
  await d.page.evaluate(([s, w]) => {
    const el = document.querySelector(s);
    if (el) el.scrollTo({ top: w === 'top' ? 0 : el.scrollHeight, behavior: 'smooth' });
  }, [sel, where]).catch(() => {});
  await d.wait(ms);
};

export default {
  title: 'AI Assistant',
  subtitle: 'Ask questions in plain English and get answers from your live FleetForge data.',
  start: '/dashboard',
  allow: [/\/api\/v1\/ai\//],
  intro: 'In this chapter we will use the AI Assistant: asking questions about your data, the floating assistant on every page, AI Analysis on a record, and how the assistant proposes changes safely.',
  outro: 'That covers the AI Assistant. Next, we will look at email, messaging and notifications.',
  scenes: [
    {
      say: 'Open AI Assistant from the sidebar. This is the full chat page, with your past conversations on the left.',
      run: async (d) => { await d.nav('AI Assistant', '/ai'); await d.wait(1500); },
    },
    {
      say: 'The assistant answers from your live data. Behind the scenes it looks up customers, leases, invoices, equipment, compliance, maintenance and accounting records.',
      run: async (d) => { await d.highlight('.ai-chat-main', 'Answers from live data', 3600); },
    },
    {
      say: 'It only sees what you are allowed to see. If your role cannot view financial figures, dollar amounts are removed before the assistant ever reads them.',
      run: async (d) => { await d.hover('.ai-chat-main h3', 2400); },
    },
    {
      say: 'For a quick start, click one of the suggested questions, or type your own in the box at the bottom.',
      run: async (d) => {
        await d.hover('button:has-text("Fleet utilization")', 700);
        await d.hover('button:has-text("Overdue invoices")', 700);
        await d.hover('button:has-text("Expiring compliance")', 700);
        await d.hover('button:has-text("Dashboard KPIs")', 700);
      },
    },
    {
      say: "Let's ask something a collections clerk might need: which customers have overdue invoices over five thousand dollars.",
      run: async (d) => {
        await d.type('.ai-chat-composer textarea', Q1, { delay: 38 });
        await d.wait(500);
        if (LIVE) await d.click('.ai-chat-composer button[type="submit"]');
        else await d.hover('.ai-chat-composer button[type="submit"]', 600);
      },
    },
    {
      say: 'While it works, the assistant shows which lookup it is running, then writes the answer as it goes.',
      run: async (d) => {
        if (!LIVE) { await d.wait(1500); return; }
        await d.moveTo(1150, 500);
        // Wait for the streamed answer to finish: composer re-enables once `sending` flips back.
        await d.page.waitForFunction(() => {
          const t = document.querySelector('.ai-chat-composer textarea');
          return t && !t.disabled && document.querySelectorAll('.ai-chat-messages [x-html="renderMarkdown(msg.content)"]').length > 0
            && [...document.querySelectorAll('.ai-chat-messages .ai-response')].some((el) => el.offsetParent && el.innerText.trim().length > 20);
        }, null, { timeout: 150000 }).catch(() => {});
        await d.wait(1200);
      },
    },
    {
      say: 'Read the answer, and check any figure before you act on it. As the note under the box says, AI responses can be wrong.',
      run: async (d) => {
        if (LIVE) { await glide(d, '.ai-chat-messages', 'top', 2200); await glide(d, '.ai-chat-messages', 'bottom', 2600); }
        await d.highlight('.ai-chat-composer', 'Always double-check', 2200);
      },
    },
    {
      say: 'Every conversation is saved under Recent. Search your chats, reopen one to continue it, or click New Chat to start fresh. The bin icon deletes a chat.',
      run: async (d) => {
        await d.highlight('.ai-chat-sidebar', 'Your chat history', 2200);
        await d.hover('input[placeholder="Search chats..."]', 700);
        await d.hover('button:has-text("New Chat")', 900);
      },
    },
    {
      say: 'The Reports tab builds a chart or table from a plain-English description, using the same generator you saw in Reports and Analytics.',
      run: async (d) => { await d.click(aiTab('Reports')); await d.wait(1200); await d.hover('button:has-text("Generate")', 1800); },
    },
    {
      say: 'Documents lets you upload a P D F or photo, such as an insurance certificate or a repair quote, and have the AI pull out and summarize the details.',
      caption: 'Documents lets you upload a PDF or photo, such as an insurance certificate or a repair quote, and have the AI pull out and summarize the details.',
      run: async (d) => {
        await d.click(aiTab('Documents')); await d.wait(1000);
        await d.hover('text=Drop a file here or click to browse', 1200);
        await d.hover('input[placeholder="Optional: specific analysis instructions..."]', 900);
        await d.hover('button:has-text("Analyze")', 900);
      },
    },
    {
      say: 'Alerts lists unusual patterns found by the nightly anomaly scan, such as a sudden jump in maintenance costs. Dismiss an alert once it has been dealt with.',
      run: async (d) => {
        await d.click(aiTab('Alerts')); await d.wait(1200);
        await d.hover('label:has-text("Unread only")', 900);
        if (await d.exists('button:has-text("Run Scan")', 1500)) await d.hover('button:has-text("Run Scan")', 1200);
      },
    },
    {
      say: 'Usage, for administrators, shows how many AI tokens were used today and this month, what they cost, and how close you are to the daily limit.',
      run: async (d) => {
        await d.click(aiTab('Usage')); await d.wait(1500);
        await d.highlight('[x-show="activeTab === \'usage\'"] > div >> nth=0', 'Tokens and cost', 2600);
        await d.hover('a:has-text("AI Settings")', 900);
      },
    },
    {
      say: 'You do not have to leave your work to ask a question. The assistant bubble in the bottom corner is on every page. The sparkle icon in the top bar opens it too.',
      run: async (d) => {
        await d.nav('Leases', '/leases');
        await d.wait(800);
        await d.hover('.topbar-ai-btn', 900);
        await d.click('button.ff-chat-fab');
        await d.wait(900);
      },
    },
    {
      say: "Let's ask it how many units are on lease, available, and in maintenance right now.",
      run: async (d) => {
        await d.type('.ff-chat-input', Q2, { delay: 38 });
        await d.wait(400);
        if (LIVE) await d.click('.ff-chat-send-btn');
        else await d.hover('.ff-chat-send-btn', 600);
      },
    },
    {
      say: 'The answer appears right in the panel, and the conversation is saved with your other chats.',
      run: async (d) => {
        if (!LIVE) { await d.wait(1500); return; }
        await d.moveTo(1650, 700);
        await d.page.waitForFunction(() => {
          const i = document.querySelector('.ff-chat-input');
          return i && !i.disabled && [...document.querySelectorAll('.ff-chat-bubble-ai .ff-chat-md')].some((el) => el.offsetParent && el.innerText.trim().length > 10);
        }, null, { timeout: 150000 }).catch(() => {});
        await d.wait(1500);
        await glide(d, '.ff-chat-messages', 'top', 1800); await glide(d, '.ff-chat-messages', 'bottom', 1800);
      },
    },
    {
      say: 'Use the header buttons to open this chat on the full page, minimize the panel to a strip, or close it.',
      run: async (d) => {
        await d.hover('.ff-chat-panel a[title="Open full chat"]', 800);
        await d.hover('.ff-chat-panel button[title="Minimize"]', 800);
        await d.hover('.ff-chat-panel button[title="Close"]', 600);
        await d.click('.ff-chat-panel button[title="Close"]');
      },
    },
    {
      say: 'The assistant can also propose changes, like updating a unit’s yard or plate, or changing a work order’s status. It never changes anything on its own.',
      run: async (d) => { await d.click('button.ff-chat-fab'); await d.wait(900); await d.highlight('.ff-chat-panel', 'Proposals, not changes', 3000); },
    },
    {
      say: 'Instead, a Confirm Change card appears with a summary. Nothing is saved until you click Apply, Cancel throws it away, and most applied changes can be undone.',
      run: async (d) => {
        // Illustration only: AI write actions are OFF on this system, so render the real
        // confirm-card component locally with an example proposal (id 0, never applied).
        await d.page.evaluate(() => {
          const el = document.querySelector('.ff-chat-panel')?.closest('[x-data]');
          const w = el && window.Alpine?.$data(el);
          if (!w) return;
          w.widgetMessages.push({
            role: 'assistant', type: 'proposal', __wtExample: true, proposalState: 'pending', proposalError: '', busy: false,
            proposal: { id: 0, affected_count: 1, undoable: true, summary: 'Update unit TR-7001 — Yard location: Delta Yard → Surrey Yard' },
          });
          setTimeout(() => w.scrollDown?.(), 50);
        }).catch(() => {});
        await d.wait(700);
        if (await d.exists('.ff-chat-proposal', 2000)) {
          await d.highlight('.ff-chat-proposal', 'Example proposal', 2400);
          await d.hover('.ff-chat-proposal-apply', 900);
          await d.hover('.ff-chat-proposal-cancel', 900);
        } else {
          await d.hover('.ff-chat-panel .ff-chat-messages', 2500);
        }
      },
    },
    {
      say: 'Change proposals only work when an administrator has turned on A I write actions in Settings, and only for records your role is allowed to edit. In this system they are currently switched off.',
      caption: 'Change proposals only work when an administrator has turned on AI write actions in Settings, and only for records your role is allowed to edit. In this system they are currently switched off.',
      run: async (d) => {
        await d.page.evaluate(() => {
          const el = document.querySelector('.ff-chat-panel')?.closest('[x-data]');
          const w = el && window.Alpine?.$data(el);
          if (w) w.widgetMessages = w.widgetMessages.filter((m) => !m.__wtExample);
        }).catch(() => {});
        await d.click('.ff-chat-panel button[title="Close"]');
        await d.wait(1500);
      },
    },
    {
      say: 'Finally, AI Analysis. Open a record, such as the customer Summit Carriers, and click AI Analysis at the top.',
      run: async (d) => {
        await d.nav('Customers', '/customers');
        await d.click('table tbody a:has-text("Summit Carriers")', { nav: true });
        await d.wait(800);
        await d.click('button:has-text("AI Analysis")');
        await d.wait(1000);
      },
    },
    {
      say: 'The panel reviews everything on the account, including leases, invoices and payment history, and writes up risks, opportunities and specific next steps.',
      run: async (d) => { await d.highlight('.ai-panel', 'AI Customer Insights', 3400); },
    },
    {
      say: 'Click Generate Now to run it. Afterwards you can copy or download the write-up, or regenerate it when the account changes. The same panel is on leases, equipment, invoices, payments, reservations and vendors.',
      run: async (d) => {
        await d.hover('.ai-panel button:has-text("Generate Now")', 2600);
        await d.click('.ai-panel__close');
        await d.wait(600);
        await d.hover('.stat-card:has-text("AI Analysis")', 1600);
      },
    },
  ],
};
