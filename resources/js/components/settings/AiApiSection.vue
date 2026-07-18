<script setup>
import { ref, reactive, computed, watch } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import TagInput from "@/components/ui/TagInput.vue";
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

const openaiModels = [
  { id: "gpt-4o-mini", label: "GPT-4o mini — $0.15/$0.60 per MTok (fast, cheap)" },
  { id: "gpt-4o", label: "GPT-4o — $2.50/$10 per MTok" },
];

const anthropicKey = ref("");
const openaiKey = ref("");
const form = reactive({
  ai_provider: props.settings.ai_provider ?? "anthropic",
  scoring_model: props.settings.scoring_model ?? "claude-haiku-4-5",
  proposal_model: props.settings.proposal_model ?? "claude-sonnet-5",
  review_model: props.settings.review_model ?? "claude-sonnet-5",
  scoring_system_prompt: props.settings.scoring_system_prompt ?? "",
  proposal_skill: props.settings.proposal_skill ?? "",
  project_facts: props.settings.project_facts ?? "",
  proposal_reference: props.settings.proposal_reference ?? "",
  proposal_quality_gate: props.settings.proposal_quality_gate ?? true,
  proposal_min_words: props.settings.proposal_min_words ?? 110,
  proposal_max_words: props.settings.proposal_max_words ?? 180,
  proposal_signature: props.settings.proposal_signature ?? "",
  proposal_required_phrases: [...(props.settings.proposal_required_phrases ?? [])],
  proposal_banned_phrases: [...(props.settings.proposal_banned_phrases ?? [])],
});
const saving = ref(false);

const modelOptions = computed(() =>
  form.ai_provider === "openai" ? openaiModels : anthropicModels,
);

// Switching provider swaps the model lists — snap any model that belongs
// to the other provider onto this provider's equivalent tier, so what you
// see is exactly what the backend will run.
watch(
  () => form.ai_provider,
  (provider) => {
    const list = provider === "openai" ? openaiModels : anthropicModels;
    if (!list.some((m) => m.id === form.scoring_model)) {
      form.scoring_model = provider === "openai" ? "gpt-4o-mini" : "claude-haiku-4-5";
    }
    if (!list.some((m) => m.id === form.proposal_model)) {
      form.proposal_model = provider === "openai" ? "gpt-4o" : "claude-sonnet-5";
    }
    if (!list.some((m) => m.id === form.review_model)) {
      form.review_model = provider === "openai" ? "gpt-4o" : "claude-sonnet-5";
    }
  },
);

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
            <option v-for="m in modelOptions" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
          <FieldHint>Runs on every lead that passes the free hard filters — keep it cheap.</FieldHint>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>Proposal model</Label>
          <select
            v-model="form.proposal_model"
            class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option v-for="m in modelOptions" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
          <FieldHint>
            Only runs when the score clears your cutoff — below-cutoff leads never pay for a
            proposal.
          </FieldHint>
        </div>
        <div>
          <Label>Review model</Label>
          <select
            v-model="form.review_model"
            class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option v-for="m in modelOptions" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
          <FieldHint>
            Grades every draft against your rules before it ships — keep this strong; a weak
            model grading its own homework is how violations slip through.
          </FieldHint>
        </div>
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
        <Label>Proposal skill (SENT to the AI — the only proposal rules the model sees)</Label>
        <Textarea
          v-model="form.proposal_skill"
          rows="14"
          class="font-mono text-xs"
          placeholder="SKILL.md v2 — the operative drafting procedure. Keep it lean: the model obeys a short skill far better than a buried one."
        />
        <FieldHint>
          Deliberately small (~9KB). The model imitates whatever it reads, so only the drafting
          procedure goes in — never the teaching document below.
        </FieldHint>
      </div>

      <div>
        <Label>Project facts (SENT to the AI — the only source of truth about your track record)</Label>
        <Textarea
          v-model="form.project_facts"
          rows="8"
          class="font-mono text-xs"
          placeholder="One line per real project: name, what it is, exact stack, what you personally built. Anything not on this sheet must never be claimed."
        />
      </div>

      <div>
        <Label>Proposal reference (NEVER sent to the AI — your reading copy)</Label>
        <Textarea
          v-model="form.proposal_reference"
          rows="8"
          class="font-mono text-xs"
          placeholder="The full teaching document — research, psychology, examples. Stored for you to read; excluded from every prompt on purpose."
        />
        <FieldHint>
          Excluded on purpose: it's written in the exact analytical, em-dash-heavy style the rules
          ban, and models copy the style of their context more than they obey instructions in it.
        </FieldHint>
      </div>

      <div class="space-y-4 rounded-md border border-border bg-neutral-bg/50 p-4">
        <div>
          <label
            class="flex items-center gap-2 text-sm font-semibold text-text-primary select-none"
          >
            <input
              type="checkbox"
              v-model="form.proposal_quality_gate"
              class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
            />
            Proposal quality gate
          </label>
          <FieldHint>
            Every proposal is mechanically checked (banned phrases, word count, signature,
            required elements, contact info) AND re-reviewed by the AI against your full rules,
            then revised up to 2 times until it complies. Turn off to accept first drafts as-is.
          </FieldHint>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <Label>Min words</Label>
            <Input type="number" v-model.number="form.proposal_min_words" />
          </div>
          <div>
            <Label>Max words</Label>
            <Input type="number" v-model.number="form.proposal_max_words" />
          </div>
          <div>
            <Label>Must end with</Label>
            <Input v-model="form.proposal_signature" placeholder="Hassam" />
            <FieldHint>First name alone on the last line. Leave empty to skip.</FieldHint>
          </div>
        </div>

        <div>
          <Label>Required elements</Label>
          <TagInput
            v-model="form.proposal_required_phrases"
            placeholder="Add a phrase every proposal must contain…"
          />
          <FieldHint>e.g. "Done =" — the mini-plan's finished-outcome definition.</FieldHint>
        </div>

        <div>
          <Label>Banned words &amp; phrases</Label>
          <TagInput
            v-model="form.proposal_banned_phrases"
            placeholder="Add a banned word or phrase…"
          />
          <FieldHint>
            AI-tell vocabulary, clichés, and the em dash (—). Single words match whole words
            only; phrases match anywhere. A draft containing any of these is auto-revised.
          </FieldHint>
        </div>
      </div>

      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save AI settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
