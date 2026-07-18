<script setup>
import { ref, reactive } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import Textarea from "@/components/ui/Textarea.vue";
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

// Verified against the current Anthropic model catalog — never guessed.
const anthropicModels = [
  { id: "claude-haiku-4-5", label: "Claude Haiku 4.5 — $1/$5 per MTok (fast, cheap)" },
  { id: "claude-sonnet-5", label: "Claude Sonnet 5 — $3/$15 per MTok (best writing)" },
  { id: "claude-sonnet-4-6", label: "Claude Sonnet 4.6 — $3/$15 per MTok" },
  { id: "claude-opus-4-8", label: "Claude Opus 4.8 — $5/$25 per MTok (most capable)" },
];

const anthropicKey = ref("");
const openaiKey = ref("");
const form = reactive({
  ai_provider: props.settings.ai_provider ?? "anthropic",
  scoring_model: props.settings.scoring_model ?? "claude-haiku-4-5",
  proposal_model: props.settings.proposal_model ?? "claude-sonnet-5",
  scoring_system_prompt: props.settings.scoring_system_prompt ?? "",
  proposal_system_prompt: props.settings.proposal_system_prompt ?? "",
});
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({
      ...form,
      anthropic_api_key: anthropicKey.value,
      openai_api_key: openaiKey.value,
    });
    anthropicKey.value = "";
    openaiKey.value = "";
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
      <CardTitle>AI models &amp; prompts</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <CardDescription>
        Scoring and proposals run directly against the AI provider's API from this server. The
        rules below are the entire brain — edit them here any time, no deploy needed.
      </CardDescription>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>Provider</Label>
          <select
            v-model="form.ai_provider"
            class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option value="anthropic">Anthropic (Claude)</option>
            <option value="openai">OpenAI</option>
          </select>
          <FieldHint>
            If the primary fails 3 times in a row, calls fail over to the other provider for 15
            minutes automatically (needs its key set).
          </FieldHint>
        </div>
        <div>
          <Label>Scoring model</Label>
          <select
            v-model="form.scoring_model"
            class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option v-for="m in anthropicModels" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
          <FieldHint>Runs on every lead that passes the free hard filters — keep it cheap.</FieldHint>
        </div>
      </div>

      <div>
        <Label>Proposal model</Label>
        <select
          v-model="form.proposal_model"
          class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
        >
          <option v-for="m in anthropicModels" :key="m.id" :value="m.id">{{ m.label }}</option>
        </select>
        <FieldHint>
          Only runs when the score clears your cutoff — below-cutoff leads never pay for a
          proposal.
        </FieldHint>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-2">
          <SecretField
            label="Anthropic API key"
            :is-set="settings.anthropic_api_key.is_set"
            :masked="settings.anthropic_api_key.masked"
            v-model="anthropicKey"
            hint="console.anthropic.com → API keys."
          />
          <TestConnectionButton service="anthropic" />
        </div>
        <div class="space-y-2">
          <SecretField
            label="OpenAI API key (failover)"
            :is-set="settings.openai_api_key.is_set"
            :masked="settings.openai_api_key.masked"
            v-model="openaiKey"
            hint="Optional — enables automatic failover."
          />
          <TestConnectionButton service="openai" />
        </div>
      </div>

      <div>
        <Label>Scoring rules (system prompt)</Label>
        <Textarea
          v-model="form.scoring_system_prompt"
          rows="14"
          class="font-mono text-xs"
          placeholder="Paste your full scoring rules here — hard auto-rejects, weights, score definitions, and the JSON output line."
        />
        <FieldHint>
          Must end by asking for JSON only:
          {"score": n, "bid": true/false, "boost": true/false, "reason": "one line"}
        </FieldHint>
      </div>

      <div>
        <Label>Proposal rules (system prompt)</Label>
        <Textarea
          v-model="form.proposal_system_prompt"
          rows="14"
          class="font-mono text-xs"
          placeholder="Paste your full proposal-writing rules here — the 225-character preview rule, opening patterns, banned phrases, self-check."
        />
      </div>

      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save AI settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
