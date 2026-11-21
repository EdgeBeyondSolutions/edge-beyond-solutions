/**
 * EdgeBeyond Chatbot Widget — embeddable, multi-tenant.
 *
 * Usage (paste before </body>):
 * <script src="https://chat.edgebeyond.solutions/widget.js"
 *         data-client-id="edgebeyond"
 *         data-widget-token="PUBLIC_WIDGET_TOKEN"
 *         data-worker-url="https://edgebeyond-chatbot.YOUR-SUBDOMAIN.workers.dev/chat"
 *         data-primary-color="#c0f447"
 *         data-dark-color="#141b24"
 *         data-greeting="Hi! Ask me anything about EdgeBeyond."
 *         defer></script>
 */
(function () {
  const scriptTag = document.currentScript;
  const clientId = scriptTag.dataset.clientId;
  const widgetToken = scriptTag.dataset.widgetToken;
  const workerUrl = scriptTag.dataset.workerUrl;
  const primary = scriptTag.dataset.primaryColor || "#c0f447";
  const dark = scriptTag.dataset.darkColor || "#141b24";
  const greeting = scriptTag.dataset.greeting || "Hi! How can I help?";

  if (!clientId || !widgetToken || !workerUrl) {
    console.error("EdgeBeyond chatbot widget: missing required data-* attributes.");
    return;
  }

  const history = [];
  const sessionKey = `eb_chat_session_${clientId}`;
  let sessionId = sessionStorage.getItem(sessionKey);
  if (!sessionId) {
    sessionId = Math.random().toString(36).slice(2);
    sessionStorage.setItem(sessionKey, sessionId);
  }

  const style = document.createElement("style");
  style.textContent = `
    .eb-chat-bubble {
      position: fixed; bottom: 24px; right: 24px; width: 60px; height: 60px;
      border-radius: 50%; background: ${dark}; border: 2px solid ${primary};
      display: flex; align-items: center; justify-content: center; cursor: pointer;
      box-shadow: 0 8px 24px rgba(0,0,0,0.25); z-index: 999999; transition: transform 0.2s;
    }
    .eb-chat-bubble:hover { transform: scale(1.06); }
    .eb-chat-bubble svg { width: 26px; height: 26px; fill: ${primary}; }
    .eb-chat-window {
      position: fixed; bottom: 96px; right: 24px; width: 360px; max-width: calc(100vw - 32px);
      height: 500px; max-height: calc(100vh - 140px); background: #fff; border-radius: 16px;
      box-shadow: 0 16px 48px rgba(0,0,0,0.3); display: none; flex-direction: column;
      overflow: hidden; z-index: 999999; font-family: system-ui, sans-serif;
      color-scheme: light;
    }
    .eb-chat-window.open { display: flex; }
    .eb-chat-header {
      background: ${dark}; color: #f3f2eb; padding: 16px 18px; font-weight: 700; font-size: 15px;
      display: flex; justify-content: space-between; align-items: center;
    }
    .eb-chat-header button { background: none; border: none; color: #8c939b; font-size: 18px; cursor: pointer; }
    .eb-chat-messages { flex: 1; overflow-y: auto; padding: 16px; background: #f6f7f5; }
    .eb-msg { max-width: 80%; margin-bottom: 10px; padding: 10px 14px; border-radius: 12px; font-size: 14px; line-height: 1.4; }
    .eb-msg.bot { background: #eef0f2; color: #141b24; border-bottom-left-radius: 2px; }
    .eb-msg.user { background: ${dark}; color: #f3f2eb; margin-left: auto; border-bottom-right-radius: 2px; }
    .eb-chat-input-row { display: flex; border-top: 1px solid #e3e5e8; padding: 10px; gap: 8px; }
    .eb-chat-input-row input {
      flex: 1; border: 1px solid #e3e5e8; border-radius: 100px; padding: 10px 14px; font-size: 14px; outline: none;
      background: #fff; color: #141b24; caret-color: #141b24; -webkit-text-fill-color: #141b24;
    }
    .eb-chat-input-row button {
      background: ${primary}; border: none; border-radius: 100px; padding: 0 18px; font-weight: 700;
      color: ${dark}; cursor: pointer; font-size: 13px;
    }
    .eb-chat-input-row button:disabled { opacity: 0.5; cursor: default; }
  `;
  document.head.appendChild(style);

  const bubble = document.createElement("div");
  bubble.className = "eb-chat-bubble";
  bubble.innerHTML = `<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.03 2 11c0 2.4 1.05 4.57 2.78 6.17L4 22l5.09-1.83C10 20.4 11 20.5 12 20.5c5.52 0 10-4.03 10-9S17.52 2 12 2z"/></svg>`;

  const win = document.createElement("div");
  win.className = "eb-chat-window";
  win.innerHTML = `
    <div class="eb-chat-header"><span>Chat</span><button aria-label="Close">&times;</button></div>
    <div class="eb-chat-messages"></div>
    <div class="eb-chat-input-row">
      <input type="text" placeholder="Type your message..." />
      <button type="button">Send</button>
    </div>
  `;

  document.body.appendChild(bubble);
  document.body.appendChild(win);

  const messagesEl = win.querySelector(".eb-chat-messages");
  const inputEl = win.querySelector("input");
  const sendBtn = win.querySelector(".eb-chat-input-row button");
  const closeBtn = win.querySelector(".eb-chat-header button");

  function addMessage(role, text) {
    const div = document.createElement("div");
    div.className = `eb-msg ${role}`;
    div.textContent = text;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  let opened = false;
  bubble.addEventListener("click", () => {
    opened = !opened;
    win.classList.toggle("open", opened);
    if (opened && messagesEl.children.length === 0) {
      addMessage("bot", greeting);
    }
  });
  closeBtn.addEventListener("click", () => {
    opened = false;
    win.classList.remove("open");
  });

  async function sendMessage() {
    const text = inputEl.value.trim();
    if (!text) return;
    addMessage("user", text);
    history.push({ role: "user", content: text });
    inputEl.value = "";
    sendBtn.disabled = true;

    try {
      const res = await fetch(workerUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          client_id: clientId,
          widget_token: widgetToken,
          session_id: sessionId,
          message: text,
          history,
        }),
      });
      const data = await res.json();
      const reply = data.reply || "Sorry, something went wrong. Please try again.";
      addMessage("bot", reply);
      history.push({ role: "assistant", content: reply });
    } catch {
      addMessage("bot", "Sorry, something went wrong. Please try again.");
    } finally {
      sendBtn.disabled = false;
      inputEl.focus();
    }
  }

  sendBtn.addEventListener("click", sendMessage);
  inputEl.addEventListener("keydown", (e) => {
    if (e.key === "Enter") sendMessage();
  });
})();
