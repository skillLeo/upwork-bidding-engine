<script setup>
import { nextTick, ref, watch } from "vue";

// Six per-digit boxes for the two-factor challenge: auto-advance on type,
// backspace steps back, and pasting a full code fills every box at once.
// The first box carries autocomplete="one-time-code" so the OS/browser can
// offer the SMS/authenticator code.
const model = defineModel({ type: String, default: "" });
const props = defineProps({
  length: { type: Number, default: 6 },
  invalid: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});

const boxes = ref([]);

function digitsOf(value) {
  return String(value ?? "")
    .replace(/\D/g, "")
    .slice(0, props.length)
    .split("");
}

function sync(digits) {
  model.value = digits.join("");
}

function onInput(index, event) {
  const raw = event.target.value.replace(/\D/g, "");
  const digits = digitsOf(model.value);

  if (!raw) {
    digits[index] = "";
    sync(digits);
    return;
  }

  // Typing a single digit (or the first of a run) lands here; multi-char from
  // an inline autofill is spread across the boxes from this position.
  const chars = raw.split("");
  for (let i = 0; i < chars.length && index + i < props.length; i += 1) {
    digits[index + i] = chars[i];
  }
  sync(digits);

  const next = Math.min(index + chars.length, props.length - 1);
  focusBox(next);
}

function onKeydown(index, event) {
  if (event.key === "Backspace") {
    const digits = digitsOf(model.value);
    if (digits[index]) {
      digits[index] = "";
      sync(digits);
    } else if (index > 0) {
      digits[index - 1] = "";
      sync(digits);
      focusBox(index - 1);
    }
    event.preventDefault();
  } else if (event.key === "ArrowLeft" && index > 0) {
    focusBox(index - 1);
  } else if (event.key === "ArrowRight" && index < props.length - 1) {
    focusBox(index + 1);
  }
}

function onPaste(event) {
  event.preventDefault();
  const pasted = (event.clipboardData?.getData("text") ?? "").replace(/\D/g, "");
  if (!pasted) return;
  const digits = pasted.slice(0, props.length).split("");
  sync(digits);
  focusBox(Math.min(digits.length, props.length - 1));
}

async function focusBox(index) {
  await nextTick();
  boxes.value[index]?.focus();
  boxes.value[index]?.select?.();
}

function boxValue(index) {
  return digitsOf(model.value)[index] ?? "";
}

// If the parent clears the model (e.g. a rejected code), reflect it.
watch(
  () => model.value,
  (value) => {
    if (value === "") return;
  },
);

defineExpose({ focusFirst: () => focusBox(0) });
</script>

<template>
  <div class="flex gap-2" role="group" aria-label="Verification code">
    <input
      v-for="i in length"
      :key="i"
      :ref="(el) => (boxes[i - 1] = el)"
      class="auth-otp"
      :class="{ 'is-invalid': invalid }"
      type="text"
      inputmode="numeric"
      :maxlength="i === 1 ? length : 1"
      :autocomplete="i === 1 ? 'one-time-code' : 'off'"
      :aria-label="`Digit ${i}`"
      :disabled="disabled"
      :value="boxValue(i - 1)"
      @input="onInput(i - 1, $event)"
      @keydown="onKeydown(i - 1, $event)"
      @paste="onPaste"
      @focus="$event.target.select()"
    />
  </div>
</template>
