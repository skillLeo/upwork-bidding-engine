<script setup>
import { ref, reactive } from "vue";
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
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

// WHAT IS NOT ON THIS SCREEN, AND WHY (P8).
//
// The AI provider, the two API keys, and the three model IDs used to live
// here. They are platform-level now: the platform pays for AI centrally out
// of pooled keys, and governs each workspace's share through that
// workspace's own monthly token cap. A workspace that could choose its own
// model could point pooled spend at the most expensive one available, so the
// models moved with the keys.
//
// The scoring rules and the drafting skill are gone for a different reason.
// They are the METHODOLOGY — how to score, how to write — and that is the
// product itself, identical for every workspace and edited only by the
// platform owner. What stays here is everything specific to THIS workspace:
// its track record, its signature, its word counts, its banned phrases.
//
// The server enforces both: the six AI keys and the prompt bodies are absent
// from the settings payload entirely, and writing a tenant override for one
// throws.

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const form = reactive({
  account_stage: props.settings.account_stage ?? "stage_1_new",
  project_facts: props.settings.project_facts ?? "",
  proposal_reference: props.settings.proposal_reference ?? "",
  proposal_writing_enabled: props.settings.proposal_writing_enabled ?? true,
  proposal_quality_gate: props.settings.proposal_quality_gate ?? true,
  proposal_min_words: props.settings.proposal_min_words ?? 90,
  proposal_max_words: props.settings.proposal_max_words ?? 150,
  proposal_signature: props.settings.proposal_signature ?? "",
  proposal_required_phrases: [...(props.settings.proposal_required_phrases ?? [])],
  proposal_banned_phrases: [...(props.settings.proposal_banned_phrases ?? [])],
});
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({ ...form });
    toast.success("Proposal settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save proposal settings."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Proposals &amp; facts</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <CardDescription>
        Everything here is yours alone — your track record, your signature, your word counts.
        How proposals are scored and written is the same for every workspace and is set by the
        platform, so it isn't editable here.
      </CardDescription>

      <div>
        <Label>Account stage</Label>
        <select
          v-model="form.account_stage"
          class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
        >
          <option value="stage_1_new">Stage 1 — new account (no reviews yet)</option>
          <option value="stage_2_established">Stage 2 — established (has reviews + JSS)</option>
        </select>
        <FieldHint>
          Stage 1 scores with tight budget floors and no BOOST recommendations. Switch to Stage 2
          once this account has reviews and a Job Success Score — scoring then raises its floors
          and allows boosting.
        </FieldHint>
      </div>

      <div>
        <Label>Project facts (sent to the AI — the only source of truth about your track record)</Label>
        <Textarea
          v-model="form.project_facts"
          rows="10"
          class="font-mono text-xs"
          placeholder="One line per real project: name, what it is, exact stack, what you personally built. Anything not on this sheet must never be claimed."
        />
        <FieldHint>
          A proposal can only claim what this sheet backs. Leave a project off and it will never
          be mentioned; describe a stack it didn't use and the claim will be caught before the
          draft ships.
        </FieldHint>
      </div>

      <div>
        <Label>Proposal reference (never sent to the AI — your reading copy)</Label>
        <Textarea
          v-model="form.proposal_reference"
          rows="8"
          class="font-mono text-xs"
          placeholder="Your own notes, research, and examples. Stored for you to read; excluded from every prompt on purpose."
        />
        <FieldHint>
          Excluded on purpose: models copy the style of whatever they read far more than they
          obey instructions in it, so long analytical prose never enters a drafting prompt.
        </FieldHint>
      </div>

      <div
        class="rounded-md border p-4"
        :class="
          form.proposal_writing_enabled
            ? 'border-border bg-neutral-bg/50'
            : 'border-warning-border bg-warning-bg'
        "
      >
        <label class="flex items-center gap-2 text-sm font-semibold select-none">
          <input
            type="checkbox"
            v-model="form.proposal_writing_enabled"
            class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
          />
          <span :class="form.proposal_writing_enabled ? 'text-text-primary' : 'text-warning'">
            Proposal writing enabled
          </span>
        </label>
        <FieldHint>
          Turn off to pause proposals everywhere while leaving scoring running. Leads still
          arrive, still get a bid/no-bid verdict, and still show on the dashboard; they just
          ship without a written proposal until this is back on.
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
            required elements, contact info) AND re-reviewed by the AI against the full rules,
            then revised up to 4 times until it complies. Turn off to accept first drafts as-is.
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
            <Input v-model="form.proposal_signature" placeholder="Your first name" />
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
        <Button @click="handleSave" :loading="saving">Save proposal settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
