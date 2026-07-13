<script setup>
import { ref } from "vue";
import { CheckCircle2, XCircle, Zap } from "@lucide/vue";
import Button from "@/components/ui/Button.vue";
import { testConnection } from "@/composables/useSettings";

const props = defineProps({ service: { type: String, required: true } });

const loading = ref(false);
const result = ref(null);

async function handleTest() {
  loading.value = true;
  result.value = null;
  try {
    result.value = await testConnection(props.service);
  } catch {
    result.value = { success: false, message: "The test request itself failed." };
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col items-end gap-1.5">
    <Button type="button" variant="secondary" size="sm" @click="handleTest" :loading="loading">
      <Zap class="h-3.5 w-3.5" /> Test connection
    </Button>
    <div
      v-if="result"
      :class="
        result.success
          ? 'flex items-start gap-1 text-xs font-medium text-success'
          : 'flex items-start gap-1 text-xs font-medium text-danger'
      "
    >
      <CheckCircle2 v-if="result.success" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <XCircle v-else class="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <span class="max-w-[220px] text-right">{{ result.message }}</span>
    </div>
  </div>
</template>
