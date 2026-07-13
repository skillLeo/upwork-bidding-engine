<script setup>
import { ref } from "vue";
import { CheckCircle2, Eye, EyeOff } from "@lucide/vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";

defineProps({
  label: { type: String, required: true },
  isSet: { type: Boolean, required: true },
  masked: { type: String, default: null },
  modelValue: { type: String, default: "" },
  hint: { type: String, default: "" },
});
defineEmits(["update:modelValue"]);

const revealed = ref(false);
</script>

<template>
  <div>
    <div class="flex items-center justify-between gap-2">
      <Label class="mb-0">{{ label }}</Label>
      <button
        v-if="isSet"
        type="button"
        @click="revealed = !revealed"
        class="flex items-center gap-1 text-xs font-medium text-text-secondary hover:text-primary"
      >
        <EyeOff v-if="revealed" class="h-3.5 w-3.5" />
        <Eye v-else class="h-3.5 w-3.5" />
        {{ revealed ? "Hide" : "Reveal" }}
      </button>
    </div>

    <div v-if="isSet" class="mb-1.5 flex items-center gap-1.5 text-xs text-text-secondary">
      <CheckCircle2 class="h-3.5 w-3.5 text-success" />
      Currently set:
      <span class="font-mono text-text-primary">{{ revealed ? masked : "•".repeat(12) }}</span>
    </div>
    <div v-else class="mb-1.5 text-xs text-text-tertiary">Not configured yet</div>

    <Input
      type="password"
      autocomplete="off"
      :model-value="modelValue"
      @update:model-value="$emit('update:modelValue', $event)"
      :placeholder="isSet ? 'Enter a new value to replace it' : 'Enter a value'"
    />
    <FieldHint v-if="hint">{{ hint }}</FieldHint>
  </div>
</template>
