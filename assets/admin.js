async function wpmcpCopy(id, button, label) {
  const input = document.getElementById(id);
  const feedback = document.getElementById('wpmcp-feedback');
  try {
    await navigator.clipboard.writeText(input.value);
    feedback.textContent = 'Copied to clipboard.';
    if (button.classList.contains('wpmcp-icon-btn')) {
      button.classList.add('is-done');
      setTimeout(() => { button.classList.remove('is-done'); }, 1800);
    } else {
      button.textContent = 'Copied';
      setTimeout(() => { button.textContent = label; }, 1800);
    }
  } catch (_) {
    input.type = 'text';
    input.focus();
    input.select();
    feedback.textContent = 'Clipboard unavailable. The value is selected; copy it manually.';
  }
}

document.addEventListener('click', async (event) => {
  const button = event.target.closest('.wpmcp button');
  if (!button) return;
  if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
    event.preventDefault();
    return;
  }
  if (button.dataset.tool) {
    document.querySelectorAll('.wpmcp-tool').forEach((tool) => {
      tool.setAttribute('aria-pressed', String(tool === button));
    });
    document.querySelectorAll('.wpmcp-steps').forEach((steps) => {
      steps.hidden = steps.dataset.steps !== button.dataset.tool;
    });
  }
  if (button.dataset.reveal) {
    const input = document.getElementById(button.dataset.reveal);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', show ? 'Hide' : 'Show');
    button.title = show ? 'Hide' : 'Show';
  }
  if (button.dataset.copy) {
    await wpmcpCopy(button.dataset.copy, button, 'Copy');
  }
  if (button.dataset.copyOpen) {
    // Open first: a popup opened after an await can be blocked by the browser.
    if (button.dataset.open) window.open(button.dataset.open, '_blank', 'noopener');
    await wpmcpCopy(button.dataset.copyOpen, button, button.dataset.open ? 'Copy URL and open again' : 'Copy URL');
  }
});

let wpmcpPopup = null;
let wpmcpTimer = null;

// Start a connection in a window we can close later, then watch for it to finish.
document.addEventListener('click', (event) => {
  const link = event.target.closest('#wpmcp-connect-claude');
  if (!link) return;
  const popup = window.open(link.href, 'wpmcp-connect');
  if (!popup) return; // Blocked: the link opens normally in a new tab instead.
  event.preventDefault();
  wpmcpPopup = popup;
  wpmcpWatch(link);
});

function wpmcpWatch(link) {
  clearInterval(wpmcpTimer);
  const status = document.getElementById('wpmcp-connect-status');
  const base = { count: Number(link.dataset.count), latest: Number(link.dataset.latest) };
  const started = Date.now();
  status.hidden = false;
  status.textContent = 'Waiting for you to approve in Claude…';
  wpmcpTimer = setInterval(async () => {
    if (Date.now() - started > 15 * 60 * 1000) {
      clearInterval(wpmcpTimer);
      status.textContent = 'Stopped waiting. Click Connect with Claude to try again.';
      return;
    }
    try {
      const response = await fetch(link.dataset.statusUrl, { credentials: 'same-origin' });
      const json = await response.json();
      if (!json.success || (json.data.count <= base.count && json.data.latest <= base.latest)) return;
      clearInterval(wpmcpTimer);
      status.textContent = 'Connected.';
      // Works only if the browser still lets this page control the window it opened.
      try { if (wpmcpPopup && !wpmcpPopup.closed) wpmcpPopup.close(); } catch (_) {}
      window.focus();
      setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('wpmcp_connected', '1');
        window.location.href = url.toString();
      }, 700);
    } catch (_) { /* Keep waiting; the next tick retries. */ }
  }, 2500);
}
