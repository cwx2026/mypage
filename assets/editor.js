/* 后台所见即所得编辑器（原生 JS，基于 contenteditable + execCommand，无依赖） */
(function () {
  'use strict';

  var editorRoot = document.querySelector('[data-editor]');
  if (!editorRoot) return;

  var toolbar = editorRoot.querySelector('[data-editor-toolbar]');
  var editor  = editorRoot.querySelector('[data-editor-content]');
  var hidden  = editorRoot.querySelector('[data-editor-hidden]');
  var form    = editorRoot.closest('form');
  if (!toolbar || !editor || !hidden) return;

  /* ---------- 工具栏命令 ---------- */
  toolbar.addEventListener('mousedown', function (e) {
    e.preventDefault(); // 防止按钮抢走选区焦点
  });

  toolbar.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cmd]');
    if (!btn) return;
    var cmd = btn.dataset.cmd;

    switch (cmd) {
      case 'createLink':
        var url = window.prompt('输入链接地址（http://…）', 'https://');
        if (!url || !/^https?:\/\//i.test(url)) { editor.focus(); return; }
        document.execCommand('createLink', false, url);
        // 新链接默认新窗口打开
        editor.querySelectorAll('a:not([target])').forEach(function (a) {
          a.target = '_blank';
          a.rel = 'noopener';
        });
        break;
      case 'insertImage':
        var src = window.prompt('输入图片地址（可用已上传的 uploads/… 或外链）', 'uploads/');
        if (!src) { editor.focus(); return; }
        document.execCommand('insertImage', false, src);
        break;
      case 'insertHr':
        document.execCommand('insertHTML', false, '<hr>');
        break;
      case 'formatBlock':
        document.execCommand('formatBlock', false, btn.dataset.value || 'p');
        break;
      default:
        document.execCommand(cmd, false, null);
    }
    editor.focus();
    updateStates();
  });

  /* ---------- 按钮激活态 ---------- */
  editor.addEventListener('keyup', updateStates);
  editor.addEventListener('mouseup', updateStates);
  function updateStates() {
    toolbar.querySelectorAll('[data-cmd]').forEach(function (btn) {
      var cmd = btn.dataset.cmd;
      if (cmd === 'createLink' || cmd === 'insertImage' || cmd === 'insertHr') return;
      var active = false;
      try { active = document.queryCommandState(cmd); } catch (err) { active = false; }
      if (cmd === 'formatBlock') {
        // 判断当前块是否为对应标签
        try {
          var value = (document.queryCommandValue(cmd) || '').toLowerCase();
          active = value === (btn.dataset.value || '');
        } catch (err) { active = false; }
      }
      btn.classList.toggle('active', active);
    });
  }

  /* ---------- 提交时把富文本写入隐藏域 ---------- */
  form.addEventListener('submit', function () {
    hidden.value = editor.innerHTML;
  });
})();
