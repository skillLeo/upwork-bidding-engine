<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";

// The signature element: five stacked "aging lead" cards on the ink panel.
// Each counter ticks up every second; every 4s the bottom card fades out and
// a new one enters at the top, the survivors shifting down and dimming a step.
// The numbers rise while the cards fade — value decaying in real time, which
// is this product's entire thesis stated without a word of copy.

// Resting opacity by position, top → bottom (brief, fixed).
const RAMP = [1.0, 0.82, 0.6, 0.38, 0.18];

function seconds(h, m, s) {
  return h * 3600 + m * 60 + s;
}

// The five starting cards, with their exact opening ages (brief).
const INITIAL = [
  { score: 9, title: "Laravel API", budget: "$1,200", age: seconds(0, 4, 12) },
  { score: 8, title: "Odoo migration", budget: "$800", age: seconds(0, 31, 47) },
  { score: 7, title: "Flutter app", budget: "$2,400", age: seconds(2, 18, 33) },
  { score: 7, title: "Next.js dashboard", budget: "$600", age: seconds(4, 12, 3) },
  { score: 6, title: "Vue landing page", budget: "$400", age: seconds(18, 44, 20) },
];

// New cards cycle a fixed array of six plausible, freshly-posted entries.
// Realistic values, varied score/title/budget, all entering young at the top.
const POOL = [
  { score: 9, title: "Django REST API", budget: "$1,800", age: seconds(0, 0, 6) },
  { score: 8, title: "React Native app", budget: "$3,200", age: seconds(0, 0, 22) },
  { score: 7, title: "Shopify theme", budget: "$700", age: seconds(0, 0, 41) },
  { score: 9, title: "FastAPI backend", budget: "$2,100", age: seconds(0, 1, 12) },
  { score: 6, title: "WordPress fixes", budget: "$350", age: seconds(0, 0, 18) },
  { score: 8, title: "Vue + Laravel SPA", budget: "$1,500", age: seconds(0, 0, 34) },
];

let uid = 0;
const cards = ref(INITIAL.map((c) => ({ ...c, id: uid++ })));

let tick = null;
let cycle = null;
let poolIndex = 0;

function formatAge(total) {
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(h)}:${pad(m)}:${pad(s)}`;
}

function advance() {
  const next = { ...POOL[poolIndex % POOL.length], id: uid++ };
  poolIndex += 1;
  // New card at the top, drop the oldest — survivors keep their keys so
  // <TransitionGroup> shifts (not re-creates) them.
  cards.value = [next, ...cards.value.slice(0, 4)];
}

onMounted(() => {
  const reduced =
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // Reduced motion: no timers at all — counters freeze at their opening
  // values and the stack never cycles. It renders static with the same ramp.
  if (reduced) return;

  tick = setInterval(() => {
    for (const card of cards.value) card.age += 1;
  }, 1000);

  cycle = setInterval(advance, 4000);
});

onBeforeUnmount(() => {
  if (tick) clearInterval(tick);
  if (cycle) clearInterval(cycle);
});
</script>

<template>
  <div class="flex flex-col items-center">
    <TransitionGroup
      tag="div"
      name="aging"
      class="relative flex flex-col items-center"
    >
      <div
        v-for="(card, index) in cards"
        :key="card.id"
        class="aging-card"
        :style="{ '--o': RAMP[index] }"
      >
        <div class="flex items-baseline gap-2">
          <span
            class="auth-mono shrink-0"
            style="font-weight: 600; font-size: 20px; color: var(--paper)"
            >{{ card.score }}</span
          >
          <span
            class="min-w-0 flex-1 truncate"
            style="font-weight: 400; font-size: 14px; color: var(--muted)"
            >{{ card.title }}</span
          >
          <span
            class="auth-mono shrink-0"
            style="font-weight: 500; font-size: 13px; color: var(--paper)"
            >{{ card.budget }}</span
          >
        </div>
        <div class="mt-2" style="font-size: 13px; color: var(--muted)">
          <span>posted </span
          ><span
            class="auth-mono"
            style="font-weight: 500"
            :style="{ color: index === 0 ? 'var(--signal)' : 'var(--muted)' }"
            >{{ formatAge(card.age) }}</span
          >
        </div>
      </div>
    </TransitionGroup>

    <p
      class="mt-6 w-[320px] text-left"
      style="font-family: var(--font-auth-sans); font-weight: 400; font-size: 15px; line-height: 1.5; color: var(--muted); text-wrap: balance"
    >
      Every lead is worth less than it was a minute ago.
    </p>
  </div>
</template>
