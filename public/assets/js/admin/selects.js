const version = new URL(import.meta.url).searchParams.get('v') || '';
const { initCustomSelects } = await import('../custom_select.js?v=' + encodeURIComponent(version));

const adminContent = document.querySelector('.admin-main');

if (adminContent) {
  initCustomSelects(adminContent);
  new MutationObserver((records) => {
    if (
      records.some((record) =>
        [...record.addedNodes].some(
          (node) =>
            node.nodeType === 1 && (node.matches?.('select') || node.querySelector?.('select')),
        ),
      )
    )
      initCustomSelects(adminContent);
  }).observe(adminContent, { childList: true, subtree: true });
}
