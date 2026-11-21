/**
 * EdgeBeyond Chatbot Worker — multi-tenant backend.
 *
 * One Worker serves any number of clients. Each client is a JSON record in
 * the CLIENTS KV namespace, keyed by client_id:
 *
 *   {
 *     "allowedOrigin": "https://edgebeyond.solutions",
 *     "widgetToken": "a random string the widget sends back",
 *     "businessName": "EdgeBeyond Solutions",
 *     "systemPrompt": "You are the assistant for ...",
 *     "knowledgeText": "Services: ... Pricing: ... FAQ: ...",
 *     "leadNotifyEmail": "contact@edgebeyond.solutions"
 *   }
 *
 * Adding a new client later = adding a new KV record. No code changes.
 */

const GEMINI_MODEL = "gemini-3.8-flash";
const GEMINI_URL = `https://generativelanguage.googleapis.com/v1beta/models/${GEMINI_MODEL}:generateContent`;
const MAX_HISTORY_TURNS = 8;
const RATE_LIMIT_PER_MINUTE = 20;

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (request.method === "OPTIONS") {
      return handlePreflight(request, env);
    }

    if (url.pathname === "/chat" && request.method === "POST") {
      return handleChat(request, env, ctx);
    }

    return new Response("Not found", { status: 404 });
  },
};

async function handlePreflight(request, env) {
  const origin = request.headers.get("Origin") || "";
  const clientId = new URL(request.url).searchParams.get("client_id");
  const client = clientId ? await getClient(env, clientId) : null;

  const headers = {
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type",
  };
  if (client && origin === client.allowedOrigin) {
    headers["Access-Control-Allow-Origin"] = origin;
  }
  return new Response(null, { status: 204, headers });
}

async function handleChat(request, env, ctx) {
  let body;
  try {
    body = await request.json();
  } catch {
    return jsonError("Invalid JSON body", 400);
  }

  const { client_id, message, history, widget_token } = body;
  if (!client_id || !message || typeof message !== "string") {
    return jsonError("Missing client_id or message", 400);
  }

  const client = await getClient(env, client_id);
  if (!client) {
    return jsonError("Unknown client_id", 404);
  }

  const origin = request.headers.get("Origin") || "";
  const corsHeaders = buildCorsHeaders(origin, client);

  if (origin !== client.allowedOrigin) {
    return jsonError("Origin not allowed for this client", 403, corsHeaders);
  }
  if (widget_token !== client.widgetToken) {
    return jsonError("Invalid widget token", 403, corsHeaders);
  }

  const ip = request.headers.get("CF-Connecting-IP") || "unknown";
  const limited = await isRateLimited(env, client_id, ip);
  if (limited) {
    return jsonError("Too many requests, please slow down.", 429, corsHeaders);
  }

  const trimmedHistory = Array.isArray(history)
    ? history.slice(-MAX_HISTORY_TURNS)
    : [];

  const systemInstruction = buildSystemInstruction(client);

  let geminiReply;
  try {
    geminiReply = await callGemini(env, systemInstruction, trimmedHistory, message);
  } catch (err) {
    console.error("Gemini call failed:", err.message);
    return jsonError("The assistant is temporarily unavailable. Please try again.", 502, corsHeaders);
  }

  const { visibleText, lead } = extractLead(geminiReply);

  if (lead && client.leadNotifyEmail && env.RESEND_API_KEY) {
    ctx.waitUntil(notifyLead(env, client, lead));
  }

  return new Response(JSON.stringify({ reply: visibleText }), {
    status: 200,
    headers: { "Content-Type": "application/json", ...corsHeaders },
  });
}

function buildCorsHeaders(origin, client) {
  const headers = {};
  if (origin === client.allowedOrigin) {
    headers["Access-Control-Allow-Origin"] = origin;
  }
  return headers;
}

async function getClient(env, clientId) {
  const raw = await env.CLIENTS.get(`client:${clientId}`);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

async function isRateLimited(env, clientId, ip) {
  const minuteBucket = Math.floor(Date.now() / 60000);
  const key = `rl:${clientId}:${ip}:${minuteBucket}`;
  const current = parseInt((await env.CLIENTS.get(key)) || "0", 10);
  if (current >= RATE_LIMIT_PER_MINUTE) return true;
  await env.CLIENTS.put(key, String(current + 1), { expirationTtl: 90 });
  return false;
}

function buildSystemInstruction(client) {
  return `${client.systemPrompt}

BUSINESS KNOWLEDGE (answer only using this information; if something isn't covered, say you don't know and offer to connect them with a human):
${client.knowledgeText}

LEAD CAPTURE: if the visitor shares their name AND (email or phone) because they want to be contacted, end your reply with a hidden block in this exact format, after your normal visible answer:
<<LEAD>>{"name":"...","email":"...","phone":"...","note":"..."}<<END_LEAD>>
Only include this block when you actually have at least a name and one contact method. Never show this block to the user as visible text — it is stripped automatically.`;
}

async function callGemini(env, systemInstruction, history, message) {
  const contents = [
    ...history.map((turn) => ({
      role: turn.role === "assistant" ? "model" : "user",
      parts: [{ text: String(turn.content || "") }],
    })),
    { role: "user", parts: [{ text: message }] },
  ];

  const payload = {
    systemInstruction: { parts: [{ text: systemInstruction }] },
    contents,
    generationConfig: { temperature: 0.4, maxOutputTokens: 2048, thinkingConfig: { thinkingBudget: 0 } },
  };

  const maxAttempts = 3;
  let lastError;
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    const res = await fetch(`${GEMINI_URL}?key=${env.GEMINI_API_KEY}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });

    if (res.ok) {
      const data = await res.json();
      const text = data?.candidates?.[0]?.content?.parts?.[0]?.text;
      if (!text) throw new Error("Empty response from Gemini");
      return text;
    }

    const bodyText = await res.text();
    lastError = new Error(`Gemini API error ${res.status}: ${bodyText.slice(0, 300)}`);

    const isRetryable = res.status === 503 || res.status === 429;
    if (!isRetryable || attempt === maxAttempts) break;
    await new Promise((r) => setTimeout(r, 400 * attempt));
  }
  throw lastError;
}

function extractLead(rawText) {
  const match = rawText.match(/<<LEAD>>([\s\S]*?)<<END_LEAD>>/);
  if (!match) {
    return { visibleText: rawText.trim(), lead: null };
  }
  const visibleText = rawText.replace(match[0], "").trim();
  let lead = null;
  try {
    lead = JSON.parse(match[1]);
  } catch {
    lead = null;
  }
  return { visibleText, lead };
}

async function notifyLead(env, client, lead) {
  try {
    await fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${env.RESEND_API_KEY}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        from: `Chatbot <chatbot@${env.RESEND_FROM_DOMAIN || "edgebeyond.solutions"}>`,
        to: [client.leadNotifyEmail],
        subject: `New chatbot lead — ${client.businessName}`,
        html: `<p>The chatbot on ${client.businessName}'s site captured a new lead:</p>
<p><strong>Name:</strong> ${escapeHtml(lead.name || "")}<br>
<strong>Email:</strong> ${escapeHtml(lead.email || "")}<br>
<strong>Phone:</strong> ${escapeHtml(lead.phone || "")}<br>
<strong>Note:</strong> ${escapeHtml(lead.note || "")}</p>`,
      }),
    });
  } catch {
    // Best-effort; a failed notification should never break the chat response.
  }
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function jsonError(message, status, extraHeaders = {}) {
  return new Response(JSON.stringify({ error: message }), {
    status,
    headers: { "Content-Type": "application/json", ...extraHeaders },
  });
}
