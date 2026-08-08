/* 个人博客 · 前端交互（原生 JS，无依赖） */
(function () {
  'use strict';

  /* ---------- 明暗主题切换 ---------- */
  var themeBtn = document.getElementById('themeToggle');
  if (themeBtn) {
    var applyTheme = function (theme) {
      document.documentElement.setAttribute('data-theme', theme);
      themeBtn.textContent = theme === 'dark' ? '☀️' : '🌙';
    };
    applyTheme(localStorage.getItem('blogTheme') || 'dark');
    themeBtn.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme') || 'dark';
      var next = current === 'dark' ? 'light' : 'dark';
      localStorage.setItem('blogTheme', next);
      applyTheme(next);
    });
  }

  /* ---------- 点赞 ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-like');
    if (!btn || btn.disabled) return;

    var postId = btn.dataset.postId;
    if (!postId) return;
    btn.disabled = true;

    fetch('api.php?action=like', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + encodeURIComponent(postId)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          var count = btn.querySelector('.like-count');
          if (count) count.textContent = data.count;
          btn.classList.add('liked');
          var hint = btn.closest('.like-bar').querySelector('.like-hint');
          if (hint) hint.textContent = '你已赞过这篇文章';
        } else {
          btn.disabled = false;
        }
      })
      .catch(function () { btn.disabled = false; });
  });

  /* ---------- 发表评论 ---------- */
  document.querySelectorAll('.comment-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = form.querySelector('[data-comment-msg]');
      var btn = form.querySelector('[type="submit"]');
      var content = (form.querySelector('[name="content"]').value || '').trim();

      if (!content) {
        if (msg) { msg.textContent = '请填写评论内容'; }
        return;
      }
      btn.disabled = true;
      if (msg) { msg.textContent = '发表中…'; }

      fetch('api.php?action=comment', {
        method: 'POST',
        body: new FormData(form)
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            location.reload();
          } else {
            if (msg) { msg.textContent = data.error || '发表失败'; }
            btn.disabled = false;
          }
        })
        .catch(function () {
          if (msg) { msg.textContent = '网络错误，请重试'; }
          btn.disabled = false;
        });
    });
  });

  /* ---------- 灯箱 ---------- */
  var overlay = null;
  function closeLightbox() { if (overlay) overlay.classList.remove('open'); }

  document.addEventListener('click', function (e) {
    var img = e.target.closest('[data-lightbox] img');
    if (!img) return;
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'lightbox';
      overlay.addEventListener('click', closeLightbox);
      document.body.appendChild(overlay);
    }
    overlay.innerHTML = '';
    var big = document.createElement('img');
    big.src = img.currentSrc || img.src;
    big.alt = img.alt || '';
    overlay.appendChild(big);
    overlay.classList.add('open');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLightbox();
  });

  /* ---------- 后台标签切换 ---------- */
  document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.dataset.tab;
      document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      document.querySelectorAll('.tab-panel').forEach(function (p) {
        p.classList.toggle('active', p.id === target);
      });
    });
  });

  // 若从「内容管理」点了编辑链接（带 #tab-write），切到对应标签
  if (location.hash === '#tab-write') {
    var btn = document.querySelector('.tab-btn[data-tab="tab-write"]');
    if (btn) btn.click();
  }
})();
