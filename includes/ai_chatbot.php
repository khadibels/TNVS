<?php
// Floating AI chatbot (admin-side)
if (!isset($BASE)) {
  $BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
}
?>
<style>
  .ai-tab {
    position: fixed;
    right: 22px;
    bottom: 22px;
    height: 38px;
    padding: 0 12px;
    border-radius: 12px;
    background: linear-gradient(135deg,#6c4bff 0%, #7f6bff 100%);
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    box-shadow: 0 10px 24px rgba(108, 75, 255, .35);
    z-index: 1080;
    border: 0;
    width: auto !important;
    min-width: 88px;
    max-width: 140px;
    white-space: nowrap;
  }
  .ai-panel {
    position: fixed;
    right: 22px;
    bottom: 76px;
    width: 360px;
    max-width: calc(100vw - 44px);
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 16px 40px rgba(31, 41, 55, .18);
    border: 1px solid #e6e1ff;
    z-index: 1080;
    display: none;
    overflow: hidden;
  }
  .ai-panel.show { display: block; }
  .ai-head {
    padding: 12px 14px;
    background: linear-gradient(135deg,#f2ecff 0%, #f8f6ff 100%);
    border-bottom: 1px solid #eee9ff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }
  .ai-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    letter-spacing: .2px;
  }
  .ai-head-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .ai-title .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #6c4bff;
    box-shadow: 0 0 0 4px rgba(108,75,255,.15);
  }
  .ai-body { padding: 12px 14px; }
  .ai-log {
    height: 260px;
    overflow: auto;
    background: #fbfaff;
    border: 1px solid #efe9ff;
    border-radius: 12px;
    padding: 8px;
    margin-bottom: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .ai-msg {
    padding: 8px 10px;
    border-radius: 10px;
    font-size: 0.9rem;
    line-height: 1.35;
    max-width: 92%;
    text-align: justify;
  }
  .ai-msg.bot {
    background: #f3f0ff;
    color: #3a2f6b;
    align-self: flex-start;
  }
  .ai-msg.user {
    background: #6c4bff;
    color: #fff;
    align-self: flex-end;
  }
  .ai-typing {
    display: inline-flex;
    gap: 4px;
    align-items: center;
    padding: 6px 10px;
    border-radius: 10px;
    background: #f1efff;
    color: #3a2f6b;
    font-size: 0.85rem;
    align-self: flex-start;
  }
  .ai-typing span {
    width: 6px;
    height: 6px;
    background: #6c4bff;
    border-radius: 50%;
    display: inline-block;
    animation: ai-bounce 1s infinite ease-in-out;
  }
  .ai-typing span:nth-child(2) { animation-delay: 0.15s; }
  .ai-typing span:nth-child(3) { animation-delay: 0.3s; }
  @keyframes ai-bounce {
    0%, 80%, 100% { transform: translateY(0); opacity: .6; }
    40% { transform: translateY(-4px); opacity: 1; }
  }
  .ai-input {
    display: flex;
    gap: 6px;
  }
  .ai-input input {
    flex: 1;
  }
</style>

<button class="ai-tab" id="aiFab" aria-label="Open AI chat">
  <ion-icon name="chatbubble-ellipses-outline"></ion-icon>
  AI Bot
</button>
<div class="ai-panel" id="aiPanel">
  <div class="ai-head">
    <div class="ai-title"><span class="dot"></span> TNVS Assistant</div>
    <div class="ai-head-actions">
      <button class="btn btn-sm btn-outline-secondary" id="aiClear" type="button">Clear Chat</button>
      <button class="btn btn-sm btn-light" id="aiClose" aria-label="Close" type="button">×</button>
    </div>
  </div>
  <div class="ai-body">
    <div class="ai-log" id="aiLog"></div>
    <div class="ai-input">
      <input class="form-control form-control-sm" id="aiQ" placeholder="Type your message…">
      <button class="btn btn-primary btn-sm" id="aiSend">Send</button>
    </div>
  </div>
</div>

<script>
(function(){
  if (window.__aiChatInit) return;
  window.__aiChatInit = true;

  const AI_BASE = '<?= $BASE ?>';
  const aiFab = document.getElementById('aiFab');
  const aiPanel = document.getElementById('aiPanel');
  const aiClose = document.getElementById('aiClose');
  const aiClear = document.getElementById('aiClear');
  const aiSend = document.getElementById('aiSend');
  const aiLog = document.getElementById('aiLog');
  const aiQ = document.getElementById('aiQ');

  function toggleAi(open){
    if (!aiPanel) return;
    aiPanel.classList.toggle('show', open);
  }

  const storeKey = 'tnvs_ai_chat_log';
  const maxMsgs = 20;

  function loadHistory(){
    try {
      const raw = localStorage.getItem(storeKey);
      return raw ? JSON.parse(raw) : [];
    } catch (e) { return []; }
  }
  function saveHistory(list){
    try { localStorage.setItem(storeKey, JSON.stringify(list.slice(-maxMsgs))); } catch (e) {}
  }
  let history = loadHistory();

  function renderHistory(){
    if (!aiLog) return;
    aiLog.innerHTML = '';
    history.forEach(m => {
      const div = document.createElement('div');
      div.className = 'ai-msg ' + (m.role === 'user' ? 'user' : 'bot');
      div.textContent = String(m.text || '').replace(/\*\*/g, '');
      aiLog.appendChild(div);
    });
    aiLog.scrollTop = aiLog.scrollHeight;
  }

  function addMsg(text, who){
    if (!aiLog) return;
    const div = document.createElement('div');
    div.className = 'ai-msg ' + who;
    aiLog.appendChild(div);
    const clean = String(text || '').replace(/\*\*/g, '');
    if (who === 'bot') {
      typeMsg(div, clean, 12);
    } else {
      div.textContent = clean;
      aiLog.scrollTop = aiLog.scrollHeight;
    }
    history.push({ role: who === 'user' ? 'user' : 'assistant', text: clean, ts: Date.now() });
    saveHistory(history);
  }
  function typeMsg(el, text, speedMs){
    let i = 0;
    el.textContent = '';
    const tick = () => {
      i++;
      el.textContent = text.slice(0, i);
      aiLog.scrollTop = aiLog.scrollHeight;
      if (i < text.length) {
        setTimeout(tick, speedMs);
      }
    };
    setTimeout(tick, speedMs);
  }
  function showTyping(show){
    if (!aiLog) return;
    let el = document.getElementById('aiTyping');
    if (show) {
      if (!el) {
        el = document.createElement('div');
        el.id = 'aiTyping';
        el.className = 'ai-typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        aiLog.appendChild(el);
      }
      aiLog.scrollTop = aiLog.scrollHeight;
    } else {
      if (el) el.remove();
    }
  }

  function clearChat(){
    history = [];
    saveHistory(history);
    if (aiLog) aiLog.innerHTML = '';
    addMsg('Chat cleared. How can I help you now?', 'bot');
  }

  aiFab?.addEventListener('click', ()=>{
    toggleAi(!aiPanel.classList.contains('show'));
    if (aiLog && aiLog.children.length === 0) {
      if (history.length === 0) {
        addMsg('Hello, this is the AI chatbot. How can I help you today?', 'bot');
      } else {
        renderHistory();
      }
    }
  });
  aiClose?.addEventListener('click', ()=> toggleAi(false));
  aiClear?.addEventListener('click', clearChat);

  window.__aiChatShipment = window.__aiChatShipment || { id: 0, ref: '' };
  window.__aiChat = {
    setShipment(id, ref){
      window.__aiChatShipment = { id: Number(id||0), ref: String(ref||'') };
    }
  };

  async function send(){
    const msg = (aiQ?.value || '').trim();
    if (!msg) return;
    addMsg(msg, 'user');
    if (aiQ) aiQ.value = '';

    try {
      showTyping(true);
      const body = new URLSearchParams();
      body.set('q', msg);
      body.set('history', JSON.stringify(history.slice(-maxMsgs)));
      if (window.__aiChatShipment.id > 0) body.set('id', String(window.__aiChatShipment.id));
      if (window.__aiChatShipment.ref) body.set('ref_no', window.__aiChatShipment.ref);

      const res = await fetch(AI_BASE + '/warehousing/TrackShipment/api/ai_chat.php', {
        method: 'POST',
        credentials: 'same-origin',
        body
      });
      const text = await res.text();
      if (!res.ok) {
        showTyping(false);
        let errMsg = 'AI not available.';
        try {
          const ej = JSON.parse(text);
          if (ej && ej.err) errMsg = ej.err;
        } catch (e) {}
        addMsg(errMsg, 'bot');
        return;
      }
      const j = JSON.parse(text);
      showTyping(false);
      addMsg(j.reply || 'No response.', 'bot');
    } catch (e) {
      showTyping(false);
      addMsg('Sorry, I could not fetch the details.', 'bot');
    }
  }

  aiSend?.addEventListener('click', send);
  aiQ?.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter') { e.preventDefault(); send(); }
  });

  // Render history on load if panel already opened
  if (history.length && aiLog) renderHistory();
})();
</script>
