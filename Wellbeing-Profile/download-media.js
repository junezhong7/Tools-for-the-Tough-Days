// 在已登录的 Wellbeing Profile video sketch 页面上，按 F12 → Console，粘贴整段运行。
// 会把页面用到的 21 个视频/图片下载到“下载”文件夹，文件名前缀 wbp-。
(async () => {
  const ids = ['TH', 'TF', 'BS', 'BP', 'AS', 'AD'];
  const files = [
    'intro-music-v1.mp4',
    'summary-music-v1.mp4',
    'this-fits-90-day-journey-mood-v3.jpg',
    ...ids.flatMap(id => [`${id}-music-v1.mp4`, `${id}-brand-v2.jpg`, `journey-${id}-music-v1.mp4`]),
  ];
  const missing = [];
  for (const [i, f] of files.entries()) {
    try {
      const r = await fetch('/media/' + f, { credentials: 'include' });
      if (!r.ok) { missing.push(`${f} (${r.status})`); continue; }
      const url = URL.createObjectURL(await r.blob());
      const a = Object.assign(document.createElement('a'), { href: url, download: 'wbp-' + f });
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      console.log(`✓ ${i + 1}/${files.length} ${f}`);
      await new Promise(s => setTimeout(s, 700));
    } catch (e) { missing.push(`${f} (${e.message})`); }
  }
  console.log(missing.length ? '没下载到：\n' + missing.join('\n') : '全部完成 ✓');
})();
