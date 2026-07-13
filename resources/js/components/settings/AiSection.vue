<script setup>
import { ref, watch } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import SecretField from "@/components/ui/SecretField.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const MODEL_SUGGESTIONS = [
  "claude-sonnet-4-6",
  "claude-opus-4-6",
  "claude-haiku-4-5",
  "claude-sonnet-4-5",
  "claude-opus-4-1",
];

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const apiKey = ref("");
const model = ref(props.settings.claude_model);
const saving = ref(false);

// Re-sync local state whenever the settings prop changes (after a save/refetch).
watch(
  () => props.settings.claude_model,
  (value) => {
    model.value = value;
  },
);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({ claude_api_key: apiKey.value, claude_model: model.value });
    apiKey.value = "";
    toast.success("AI settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save AI settings."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>AI (Claude)</CardTitle>
      <TestConnectionButton service="claude" />
    </CardHeader>
    <CardContent class="space-y-4">
      <SecretField
        label="Claude API key"
        :is-set="settings.claude_api_key.is_set"
        :masked="settings.claude_api_key.masked"
        v-model="apiKey"
        hint="Passed to OpenClaw with every scoring/drafting request — never stored in .env."
      />
      <div>
        <Label for="claude_model">Claude model</Label>
        <input
          id="claude_model"
          list="claude-model-options"
          v-model="model"
          class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
        />
        <datalist id="claude-model-options">
          <option v-for="m in MODEL_SUGGESTIONS" :key="m" :value="m" />
        </datalist>
        <FieldHint>Pick a suggestion or type any current model ID.</FieldHint>
      </div>
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save AI settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
