<script setup>
import { ref, computed } from "vue";
import { X } from "@lucide/vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  modelValue: { type: Array, required: true },
  placeholder: { type: String, default: "" },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

const draft = ref("");

function commitDraft() {
  const trimmed = draft.value.trim();
  if (trimmed && !props.modelValue.includes(trimmed)) {
    emit("update:modelValue", [...props.modelValue, trimmed]);
  }
  draft.value = "";
}

function removeTag(tag) {
  emit("update:modelValue", props.modelValue.filter((t) => t !== tag));
}

function onKeydown(e) {
  if (e.key === "Enter" || e.key === ",") {
    e.preventDefault();
    commitDraft();
  } else if (e.key === "Backspace" && draft.value === "" && props.modelValue.length > 0) {
    removeTag(props.modelValue[props.modelValue.length - 1]);
  }
}

const wrapperClass = computed(() =>
  cn(
    "flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-md border border-border-strong bg-white px-2.5 py-1.5",
    "focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20",
    props.disabled && "cursor-not-allowed bg-black/5",
  ),
);
</script>

<template>
  <div :class="wrapperClass">
    <span
      v-for="tag in modelValue"
      :key="tag"
      class="inline-flex items-center gap-1 rounded-pill bg-primary-tint px-2 py-1 text-xs font-medium text-primary"
    >
      {{ tag }}
      <button
        v-if="!disabled"
        type="button"
        @click="removeTag(tag)"
        class="rounded-full hover:text-primary-pressed"
        :aria-label="`Remove ${tag}`"
      >
        <X class="h-3 w-3" />
      </button>
    </span>
    <input
      v-if="!disabled"
      v-model="draft"
      @keydown="onKeydown"
      @blur="commitDraft"
      :placeholder="modelValue.length === 0 ? placeholder : ''"
      class="min-w-[120px] flex-1 border-none bg-transparent py-1 text-sm outline-none placeholder:text-text-tertiary"
    />
  </div>
</template>
