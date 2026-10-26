import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useRef, useState, type ReactNode } from "react";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "EdgeBeyond — AI Marketing Automation, Beyond the Ordinary" },
      { name: "description", content: "Stop chasing leads at midnight. EdgeBeyond builds AI agents, automation and marketing that grow your business 24/7." },
      { property: "og:title", content: "EdgeBeyond — Your business, beyond the edge" },
      { property: "og:description", content: "AI agents, automation and marketing that work while you sleep. Get your free AI Profit Roadmap." },
    ],
  }),
  component: Index,
});

function useReveal() {
  useEffect(() => {
    const io = new IntersectionObserver(
      (es) => es.forEach((e) => e.isIntersecting && e.target.classList.add("in")),
      { threshold: 0.15 },
    );
    document.querySelectorAll(".reveal").forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, []);
}

/* subtle 3D tilt on every .tilt-card, site-wide */
function useCardTilt() {
  useEffect(() => {
    const els = Array.from(document.querySelectorAll<HTMLElement>(".tilt-card"));
    const cleanups = els.map((el) => {
      const move = (e: PointerEvent) => {
        const r = el.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - 0.5;
        const y = (e.clientY - r.top) / r.height - 0.5;
        el.style.setProperty("--ry", `${x * 7}deg`);
        el.style.setProperty("--rx", `${-y * 7}deg`);
      };
      const leave = () => {
        el.style.setProperty("--ry", "0deg");
        el.style.setProperty("--rx", "0deg");
      };
      el.addEventListener("pointermove", move);
      el.addEventListener("pointerleave", leave);
      return () => {
        el.removeEventListener("pointermove", move);
        el.removeEventListener("pointerleave", leave);
      };
    });
    return () => cleanups.forEach((f) => f());
  }, []);
}

/* fixed holographic environment behind the whole site */
function Ambient() {
  return (
    <div className="pointer-events-none fixed inset-0 -z-10" aria-hidden>
      <div className="absolute inset-0 horizon opacity-70" />
      <div className="absolute inset-0 grid-lines" />
      <div className="holo-beam absolute left-[10%] top-0 h-full w-56 -skew-x-12 opacity-50" />
      <div className="holo-beam absolute right-[12%] top-0 h-full w-32 skew-x-6 opacity-35" />
      <div className="holo-beam absolute left-[55%] top-0 h-full w-24 skew-x-3 opacity-25" />
      <div className="floor-glow absolute -bottom-40 left-1/2 h-96 w-[90%] -translate-x-1/2" />
    </div>
  );
}

function Logo() {
  return (
    <a href="#top" className="flex items-center gap-2.5" aria-label="EdgeBeyond">
      <svg width="30" height="30" viewBox="0 0 32 32" fill="none" aria-hidden>
        <defs>
          <linearGradient id="eb" x1="0" y1="0" x2="32" y2="32">
            <stop stopColor="var(--primary)" />
            <stop offset="1" stopColor="var(--accent)" />
          </linearGradient>
        </defs>
        <path d="M4 22 L16 22 M4 16 L13 16 M4 10 L16 10" stroke="var(--foreground)" strokeWidth="2.4" strokeLinecap="round" />
        <path d="M18 6 L28 16 L18 26" stroke="url(#eb)" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span className="text-lg font-medium tracking-tight">
        Edge<span className="text-edge">Beyond</span>
      </span>
    </a>
  );
}

const chapters = [
  ["01", "The challenge", "#challenge"],
  ["02", "The journey", "#journey"],
  ["03", "The turning point", "#turning"],
  ["04", "Beyond", "#beyond"],
] as const;

function Nav() {
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const f = () => setScrolled(window.scrollY > 40);
    window.addEventListener("scroll", f);
    return () => window.removeEventListener("scroll", f);
  }, []);
  return (
    <header className={`fixed inset-x-0 top-0 z-40 transition-all duration-500 ${scrolled ? "glass border-x-0 border-t-0" : ""}`}>
      <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
        <Logo />
        <nav className="hidden items-center gap-8 md:flex">
          {chapters.map(([n, t, h]) => (
            <a key={h} href={h} className="group text-sm text-muted-foreground transition-colors hover:text-foreground">
              <span className="mr-1.5 font-mono text-xs text-primary/70">{n}</span>
              {t}
            </a>
          ))}
        </nav>
        <a href="#begin" className="rounded-full bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground shadow-glow transition-transform hover:scale-105">
          Start your story
        </a>
      </div>
    </header>
  );
}

function Chapter({ n, label, tone = "primary" }: { n: string; label: string; tone?: "primary" | "pain" }) {
  return (
    <div className="reveal mb-10 flex items-center gap-4 font-mono text-xs uppercase tracking-[0.3em]">
      <span className={tone === "pain" ? "text-pain" : "text-primary"}>Chapter {n}</span>
      <span className="h-px w-16 bg-border" />
      <span className="text-muted-foreground">{label}</span>
    </div>
  );
}

function H2({ children }: { children: ReactNode }) {
  return <h2 className="reveal max-w-4xl font-display text-5xl leading-[1.02] tracking-tight md:text-7xl">{children}</h2>;
}

/* ---------- 3D holographic hero panel ---------- */

function HoloPanel() {
  const ref = useRef<HTMLDivElement>(null);

  const onMove = (e: React.PointerEvent) => {
    const el = ref.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width - 0.5;
    const y = (e.clientY - r.top) / r.height - 0.5;
    el.style.setProperty("--ry", `${-10 + x * 14}deg`);
    el.style.setProperty("--rx", `${8 - y * 12}deg`);
  };
  const onLeave = () => {
    const el = ref.current;
    if (!el) return;
    el.style.setProperty("--ry", "-10deg");
    el.style.setProperty("--rx", "8deg");
  };

  return (
    <div className="tilt-scene relative mx-auto w-full max-w-xl" onPointerMove={onMove} onPointerLeave={onLeave}>
      {/* glow on the "floor" */}
      <div className="floor-glow absolute -bottom-16 left-1/2 h-40 w-[120%] -translate-x-1/2" aria-hidden />

      <div ref={ref} className="tilt-panel relative">
        {/* back ghost layer for depth */}
        <div className="glass absolute inset-0 translate-x-5 translate-y-5 rounded-3xl opacity-40" aria-hidden />

        {/* main glass dashboard */}
        <div className="glass-edge relative rounded-3xl p-6 md:p-8">
          {/* window chrome */}
          <div className="flex items-center gap-2">
            <span className="h-2.5 w-2.5 rounded-full bg-pain/80" />
            <span className="h-2.5 w-2.5 rounded-full bg-primary/70" />
            <span className="h-2.5 w-2.5 rounded-full bg-accent/80" />
            <span className="ml-3 font-mono text-[10px] uppercase tracking-[0.25em] text-muted-foreground">edgebeyond · live</span>
          </div>

          <p className="mt-6 font-display text-3xl italic tracking-tight md:text-4xl">
            Your business, <span className="text-edge">beyond.</span>
          </p>

          {/* line chart */}
          <div className="glass layer-1 mt-6 rounded-2xl p-4">
            <div className="flex items-baseline justify-between">
              <p className="font-mono text-[10px] uppercase tracking-widest text-muted-foreground">Qualified leads</p>
              <p className="font-mono text-xs text-primary">+212%</p>
            </div>
            <svg viewBox="0 0 300 90" className="mt-2 w-full" aria-hidden>
              <defs>
                <linearGradient id="area" x1="0" y1="0" x2="0" y2="1">
                  <stop stopColor="var(--accent)" stopOpacity="0.35" />
                  <stop offset="1" stopColor="var(--accent)" stopOpacity="0" />
                </linearGradient>
              </defs>
              <path d="M0 75 L40 62 L80 68 L120 48 L160 52 L200 30 L240 34 L300 10 L300 90 L0 90 Z" fill="url(#area)" />
              <path d="M0 75 L40 62 L80 68 L120 48 L160 52 L200 30 L240 34 L300 10" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" />
              <path d="M0 80 L40 74 L80 76 L120 60 L160 64 L200 44 L240 46 L300 24" fill="none" stroke="var(--primary)" strokeWidth="1.5" strokeDasharray="4 4" opacity="0.7" />
            </svg>
          </div>

          <div className="mt-4 grid grid-cols-2 gap-4">
            {/* bars */}
            <div className="glass layer-1 rounded-2xl p-4">
              <p className="font-mono text-[10px] uppercase tracking-widest text-muted-foreground">Booked calls</p>
              <div className="mt-3 flex h-16 items-end gap-1.5">
                {[30, 45, 38, 55, 50, 70, 62, 85].map((h, i) => (
                  <span
                    key={i}
                    className="flex-1 rounded-sm bg-gradient-to-t from-accent/30 to-primary/80"
                    style={{ height: `${h}%` }}
                  />
                ))}
              </div>
            </div>
            {/* donut */}
            <div className="glass layer-1 flex items-center gap-3 rounded-2xl p-4">
              <svg viewBox="0 0 40 40" className="h-14 w-14 -rotate-90" aria-hidden>
                <circle cx="20" cy="20" r="16" fill="none" stroke="var(--border)" strokeWidth="5" />
                <circle cx="20" cy="20" r="16" fill="none" stroke="var(--primary)" strokeWidth="5" strokeDasharray="100.5" strokeDashoffset="27" strokeLinecap="round" />
              </svg>
              <div>
                <p className="text-xl font-medium">73%</p>
                <p className="font-mono text-[10px] uppercase tracking-widest text-muted-foreground">automated</p>
              </div>
            </div>
          </div>
        </div>

        {/* floating chips at different depths */}
        <div className="glass layer-3 animate-float absolute -left-6 -top-8 hidden rounded-2xl px-4 py-3 md:block">
          <p className="font-mono text-[10px] uppercase tracking-widest text-muted-foreground">2:14 AM</p>
          <p className="mt-0.5 text-sm">Lead answered instantly</p>
        </div>
        <div className="glass layer-2 animate-float-slow absolute -bottom-8 -right-4 hidden rounded-2xl px-4 py-3 md:block">
          <p className="font-mono text-[10px] uppercase tracking-widest text-primary">While you slept</p>
          <p className="mt-0.5 text-sm">3 calls booked ✦</p>
        </div>
      </div>
    </div>
  );
}

/* ---------- content ---------- */

const pains = [
  ["11:47 PM", "A prospect asks a question on your site. Nobody answers. By morning, they hired someone else."],
  ["Monday, again", "Hours lost copying data, sending follow-ups, and chasing tasks a machine should do."],
  ["Every month", "You pay for marketing and still can't tell what's working — or why the calendar is empty."],
  ["Right now", "Your competitors are adopting AI. You know it. And it keeps you up at night."],
];

const tried = [
  "Another agency that promised the world",
  "Five new tools nobody on the team uses",
  "Longer days, fewer weekends",
  "Ads that burned budget, not leads",
];

const steps = [
  ["Analyze", "Our AI scans your business in 60 seconds and finds exactly where you're losing time and money."],
  ["Strategize", "You get a Strategic Flight Plan — the highest-ROI AI moves, prioritized for you."],
  ["Build & automate", "We design the website, agents and automations, and put them to work."],
  ["Grow", "Your systems run 24/7, filling your calendar with qualified prospects."],
];

const solutions = [
  ["AI Web Design", "A fast, beautiful stage where SEO, automation and AI come together to convert."],
  ["AI SEO", "Rank in search results and in AI answer engines — when your customer is looking."],
  ["AI PPC Ads", "Google and social campaigns that bring qualified traffic and feed your automations."],
  ["AI Chatbots", "Always-on assistants that answer, guide and qualify leads in real time."],
  ["AI Agents", "Digital brains that analyze, decide and act — far beyond a chatbot."],
  ["AI Automations", "Invisible engines handling the repetitive work so your team doesn't have to."],
];

const faqs = [
  ["What is an AI Marketing Automation agency?", "An agency that combines web design, digital marketing and AI agents to attract qualified prospects and grow your business with less manual effort."],
  ["How fast can you deliver?", "In weeks, not months. We start with a scan, prioritize the highest-ROI moves and build in phases so you see value early."],
  ["Do you work with small businesses?", "Yes — small and medium businesses are exactly who we build for. You talk directly with the people who design and build your solution."],
];

function Index() {
  useReveal();
  useCardTilt();
  return (
    <div id="top" className="grain overflow-x-clip">
      <Ambient />
      <Nav />

      {/* HERO — holographic 3D */}
      <section className="relative flex min-h-screen items-center overflow-hidden">
        <div className="absolute inset-0 grid-lines" />
        <div className="absolute inset-0 horizon" />
        {/* light beams from above, like the reference */}
        <div className="holo-beam absolute left-[15%] top-0 h-[70%] w-40 -skew-x-12 opacity-60" aria-hidden />
        <div className="holo-beam absolute right-[20%] top-0 h-[55%] w-24 skew-x-6 opacity-40" aria-hidden />

        <div className="relative mx-auto grid w-full max-w-7xl items-center gap-16 px-6 pb-24 pt-32 lg:grid-cols-2">
          <div>
            <p className="reveal font-mono text-xs uppercase tracking-[0.3em] text-muted-foreground">
              AI Marketing Automation · Est. for the bold
            </p>
            <h1 className="reveal mt-8 font-display text-6xl leading-[0.95] tracking-tight md:text-8xl">
              Every business
              <br />
              reaches an edge.
              <br />
              <em className="text-edge">Yours goes beyond.</em>
            </h1>
            <p className="reveal mt-10 max-w-md text-lg text-muted-foreground">
              This is the story of an owner who almost gave up — and the AI that changed the ending. It might be yours.
            </p>
            <div className="reveal mt-10 flex flex-wrap items-center gap-6">
              <a href="#begin" className="rounded-full bg-primary px-7 py-3.5 font-medium text-primary-foreground shadow-glow transition-transform hover:scale-105">
                Get my free roadmap
              </a>
              <a href="#challenge" className="group flex items-center gap-3 text-sm text-muted-foreground transition-colors hover:text-foreground">
                <span className="glass flex h-11 w-11 items-center justify-center rounded-full transition-colors group-hover:text-primary">↓</span>
                Begin the story
              </a>
            </div>
          </div>

          <div className="reveal">
            <HoloPanel />
          </div>
        </div>
      </section>

      {/* CH 1 — PAIN */}
      <section id="challenge" className="mx-auto max-w-7xl px-6 py-32 md:py-48">
        <Chapter n="01" label="The challenge" tone="pain" />
        <H2>
          You built something real. <span className="text-muted-foreground">Now it's running you.</span>
        </H2>
        <div className="tilt-scene mt-20 grid gap-6 md:grid-cols-2">
          {pains.map(([t, d], i) => (
            <div
              key={t}
              className="reveal glass tilt-panel tilt-card rounded-3xl p-10 transition-all duration-300 hover:-translate-y-2 hover:shadow-glow"
              style={{ transitionDelay: `${i * 80}ms`, ["--rx" as string]: "0deg", ["--ry" as string]: "0deg" }}
            >
              <p className="font-mono text-xs uppercase tracking-widest text-pain">{t}</p>
              <p className="mt-5 text-xl leading-relaxed md:text-2xl">{d}</p>
            </div>
          ))}
        </div>
      </section>

      {/* CH 2 — JOURNEY & BREAKING POINT */}
      <section id="journey" className="relative py-32 md:py-48">
        <div className="mx-auto max-w-7xl px-6">
          <Chapter n="02" label="The journey" tone="pain" />
          <H2>So you tried everything.</H2>
          <ul className="mt-16 space-y-4">
            {tried.map((t, i) => (
              <li
                key={t}
                className="reveal glass tilt-panel tilt-card flex items-baseline gap-6 rounded-2xl px-8 py-6 text-2xl text-muted-foreground line-through decoration-pain/60 md:text-4xl"
                style={{ transitionDelay: `${i * 100}ms` }}
              >
                <span className="font-mono text-sm no-underline text-pain">✕</span>
                {t}
              </li>
            ))}
          </ul>
        </div>
        <div className="mx-auto mt-40 max-w-4xl px-6 text-center">
          <p className="reveal font-mono text-xs uppercase tracking-[0.3em] text-muted-foreground">The breaking point</p>
          <blockquote className="reveal mt-8 font-display text-4xl italic leading-tight text-muted-foreground md:text-6xl">
            “Maybe this is as far as it goes.”
          </blockquote>
          <p className="reveal mt-10 text-lg text-muted-foreground">Every owner has thought it. Very few stop there.</p>
        </div>
      </section>

      {/* CH 3 — TURNING POINT */}
      <section id="turning" className="relative overflow-hidden py-32 md:py-48">
        <div className="absolute inset-0 horizon opacity-70" />
        <div className="holo-beam absolute right-[10%] top-0 h-[60%] w-32 skew-x-12 opacity-40" aria-hidden />
        <div className="relative mx-auto max-w-7xl px-6">
          <Chapter n="03" label="The turning point" />
          <H2>
            Then, in <span className="text-edge">60 seconds</span>, everything changed.
          </H2>
          <p className="reveal mt-8 max-w-xl text-lg text-muted-foreground">
            Our AI scanned the business and revealed what no one had seen: where time leaked, where money hid, and the exact path forward.
          </p>
          <div className="tilt-scene mt-20 grid gap-6 md:grid-cols-4">
            {steps.map(([t, d], i) => (
              <div
                key={t}
                className="reveal glass tilt-panel tilt-card group relative rounded-3xl p-8 transition-all duration-300 hover:-translate-y-2 hover:shadow-glow"
                style={{ transitionDelay: `${i * 120}ms` }}
              >
                <span className="font-mono text-sm text-primary">0{i + 1}</span>
                <h3 className="mt-10 text-2xl font-medium">{t}</h3>
                <p className="mt-3 text-muted-foreground">{d}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CH 4 — BEYOND / SOLUTIONS */}
      <section id="beyond" className="mx-auto max-w-7xl px-6 py-32 md:py-48">
        <Chapter n="04" label="Beyond" />
        <H2>
          Now the business runs itself. <em className="text-edge">The owner finally breathes.</em>
        </H2>
        <div className="reveal mt-16 grid gap-6 md:grid-cols-3">
          {["Leads answered at 2 AM", "Calls booked while you sleep", "Weekends, back"].map((w) => (
            <div key={w} className="glass tilt-panel tilt-card flex items-center gap-4 rounded-full px-6 py-4">
              <span className="h-2 w-2 rounded-full bg-primary shadow-glow" />
              <span className="text-lg">{w}</span>
            </div>
          ))}
        </div>
        <p className="reveal mt-28 font-mono text-xs uppercase tracking-[0.3em] text-muted-foreground">The tools behind the ending</p>
        <div className="mt-8 grid gap-4">
          {solutions.map(([t, d], i) => (
            <div
              key={t}
              className="reveal glass tilt-panel tilt-card group grid items-baseline gap-4 rounded-2xl px-8 py-8 transition-all duration-300 hover:-translate-y-1 hover:shadow-glow md:grid-cols-[80px_1fr_1.2fr]"
            >
              <span className="font-mono text-sm text-muted-foreground">0{i + 1}</span>
              <h3 className="font-display text-4xl transition-colors group-hover:text-primary md:text-5xl">{t}</h3>
              <p className="text-muted-foreground">{d}</p>
            </div>
          ))}
        </div>
      </section>

      {/* MARQUEE */}
      <div className="glass overflow-hidden border-x-0 py-6">
        <div className="flex w-max animate-marquee gap-12 font-display text-4xl italic text-muted-foreground">
          {Array.from({ length: 2 }).flatMap((_, k) =>
            ["Beyond the ordinary", "✦", "Beyond the busywork", "✦", "Beyond 9 to 5", "✦", "Beyond the edge", "✦"].map((w, i) => <span key={`${k}-${i}`}>{w}</span>),
          )}
        </div>
      </div>

      {/* FAQ */}
      <section className="mx-auto max-w-4xl px-6 py-32">
        <p className="reveal font-mono text-xs uppercase tracking-[0.3em] text-muted-foreground">Before you begin</p>
        <div className="mt-8 grid gap-4">
          {faqs.map(([q, a]) => (
            <details key={q} className="reveal glass tilt-panel tilt-card group rounded-2xl px-8 py-6">
              <summary className="flex cursor-pointer list-none items-center justify-between text-xl md:text-2xl">
                {q}
                <span className="text-primary transition-transform group-open:rotate-45">+</span>
              </summary>
              <p className="mt-4 max-w-2xl text-muted-foreground">{a}</p>
            </details>
          ))}
        </div>
      </section>

      {/* CTA */}
      <section id="begin" className="relative overflow-hidden py-40">
        <div className="absolute inset-0 horizon" />
        <div className="absolute inset-0 grid-lines" />
        <div className="holo-beam absolute left-[30%] top-0 h-[70%] w-40 -skew-x-6 opacity-50" aria-hidden />
        <div className="relative mx-auto max-w-4xl px-6 text-center">
          <p className="reveal font-mono text-xs uppercase tracking-[0.3em] text-primary">Chapter 05 · Yours</p>
          <h2 className="reveal mt-8 font-display text-6xl leading-none tracking-tight md:text-8xl">
            Write the ending <em className="text-edge">you deserve.</em>
          </h2>
          <p className="reveal mx-auto mt-8 max-w-lg text-lg text-muted-foreground">
            Get your free AI Profit Roadmap. 60 seconds. Delivered to your inbox. No strings.
          </p>
          <form
            className="reveal glass-edge mx-auto mt-12 flex max-w-lg flex-col gap-3 rounded-full p-2 sm:flex-row"
            onSubmit={(e) => {
              e.preventDefault();
              const email = new FormData(e.currentTarget).get("email");
              window.location.href = `mailto:hello@edgebeyond.solutions?subject=My free AI Profit Roadmap&body=My email: ${email}`;
            }}
          >
            <input name="email" type="email" required placeholder="you@business.com" className="flex-1 bg-transparent px-5 py-3 outline-none placeholder:text-muted-foreground" />
            <button className="rounded-full bg-primary px-6 py-3 font-medium text-primary-foreground shadow-glow transition-transform hover:scale-105">
              Get my roadmap →
            </button>
          </form>
        </div>
      </section>

      <footer className="glass border-x-0 border-b-0">
        <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-6 py-10 text-sm text-muted-foreground md:flex-row">
          <Logo />
          <p>Serving businesses across the United States</p>
          <p>© {new Date().getFullYear()} EdgeBeyond</p>
        </div>
      </footer>
    </div>
  );
}
