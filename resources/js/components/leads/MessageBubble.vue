<script setup>
import { computed } from "vue";
import { Copy, MessageCircleWarning } from "@lucide/vue";
import { cn, formatDateTime } from "@/lib/utils";

const props = defineProps({ message: { type: Object, required: true } });
const emit = defineEmits(["copy"]);

const isOut = computed(() => props.message.direction === "out");
</script>

<template>
  <div :class="cn('flex', isOut ? 'justify-end' : 'justify-start')">
    <div
      :class="
        cn(
          'max-w-[85%] rounded-card px-4 py-3 text-sm',
          isOut ? 'bg-primary text-white' : 'bg-neutral-bg text-text-primary',
        )
      "
    >
      <p class="whitespace-pre-wrap">{{ message.text }}</p>
      <div
        :class="
          cn(
            'mt-1.5 flex items-center gap-2 text-[11px]',
            isOut ? 'text-white/70' : 'text-text-tertiary',
          )
        "
      >
        {{ formatDateTime(message.sent_at ?? message.created_at) }}
        <span
          v-if="message.needs_hassam"
          class="inline-flex items-center gap-1 rounded-pill bg-warning-bg px-1.5 py-0.5 font-semibold text-warning"
        >
          <MessageCircleWarning class="h-3 w-3" /> Needs Hassam
        </span>
      </div>
      <button
        v-if="message.drafted_reply && !isOut"
        @click="emit('copy', message.drafted_reply)"
        class="mt-2 flex items-center gap-1 text-xs font-medium text-primary hover:underline"
      >
        <Copy class="h-3 w-3" /> Copy drafted reply
      </button>
    </div>
  </div>
</template>
