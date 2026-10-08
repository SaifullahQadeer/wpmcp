async function wpmcpCopy(id, button, label) {
  const input = document.getElementById(id);
  const feedback = document.getElementById('wpmcp-feedback');
  try {
    await navigator.clipboard.writeText(input.value);
    button.textContent = 'Copied';
    feedback.textContent = 'Copied to clipboard.';
    setTimeout(() => { button.textContent = label; }, 1800);
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
    button.textContent = show ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(show));
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
