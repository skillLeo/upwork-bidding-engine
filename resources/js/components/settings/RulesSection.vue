<script setup>
import { ref, watch } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import TagInput from "@/components/ui/TagInput.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const form = ref({ ...props.settings });
const saving = ref(false);

// Re-sync local form state whenever the settings prop changes (after a save/refetch).
watch(
  () => props.settings,
  (value) => {
    form.value = { ...value };
  },
);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({ ...form.value });
    toast.success("Rules saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save rules."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Bidding rules</CardTitle>
    </CardHeader>
    <CardContent class="space-y-5">
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <div>
          <Label>Min budget ($)</Label>
          <Input type="number" v-model.number="form.min_budget" />
        </div>
        <div>
          <Label>Max proposals</Label>
          <Input type="number" v-model.number="form.max_proposals" />
        </div>
        <div>
          <Label>Score cutoff</Label>
          <Input type="number" v-model.number="form.score_cutoff" />
          <FieldHint>1–10</FieldHint>
        </div>
        <div>
          <Label>Hourly floor ($/hr)</Label>
          <Input type="number" v-model.number="form.hourly_floor" />
        </div>
        <div>
          <Label>Zero-history budget floor ($)</Label>
          <Input type="number" v-model.number="form.zero_history_budget_floor" />
        </div>
        <div>
          <Label>Follow up after (days)</Label>
          <Input type="number" v-model.number="form.followup_days" />
        </div>
        <div>
          <Label>Max posting age for AI scoring (days)</Label>
          <Input type="number" v-model.number="form.max_posted_age_days" />
          <FieldHint>Older postings are archived without an AI call — they stay visible, just unscored.</FieldHint>
        </div>
        <div>
          <Label>WhatsApp alert freshness (hours)</Label>
          <Input type="number" v-model.number="form.notification_freshness_hours" />
          <FieldHint>
            Leads older than this at scoring time are still scored and written, but never ring
            your phone — fresh leads are the strategy. 0 disables the gate.
          </FieldHint>
        </div>
      </div>

      <div class="space-y-4 rounded-md border border-border bg-neutral-bg/40 p-4">
        <p class="text-sm font-semibold text-text-primary">Your stacks</p>
        <FieldHint>
          The single source of truth for what you do. Scoring and proposal writing both read these
          three lists — no stack is hardcoded anywhere else. Move a stack between lists and it takes
          effect on the next score or rewrite, no deploy needed.
        </FieldHint>

        <div>
          <Label>Core stacks — your lead pitch (score high, claim freely)</Label>
          <TagInput v-model="form.core_stacks" placeholder="e.g. Laravel, Vue, Flutter…" />
          <FieldHint>Left = most defensible. These are what you lead with and score highest.</FieldHint>
        </div>

        <div>
          <Label>Secondary stacks — can do, not your lead story (score medium)</Label>
          <TagInput v-model="form.secondary_stacks" placeholder="e.g. Node.js, React, MongoDB…" />
          <FieldHint>
            Score medium and may be mentioned as a capability, but never claimed as the core of a
            named past project unless your project facts back it.
          </FieldHint>
        </div>

        <div>
          <Label>Excluded stacks — out of scope (score low, never claim)</Label>
          <TagInput v-model="form.excluded_stacks" placeholder="e.g. Ruby, Go, .NET…" />
          <FieldHint>
            A job that core-requires one of these is a low score / no-bid, and the proposal linter
            blocks any proposal that claims one.
          </FieldHint>
        </div>
      </div>

      <div>
        <Label>Red-flag phrases</Label>
        <TagInput v-model="form.red_flag_words" placeholder="Add a phrase and press Enter…" />
        <FieldHint>Any brief containing one of these is archived before any AI call runs.</FieldHint>
      </div>

      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save rules</Button>
      </div>
    </CardContent>
  </Card>
</template>
