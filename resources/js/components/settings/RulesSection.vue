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
      </div>

      <div>
        <Label>Stack keywords</Label>
        <TagInput v-model="form.stack_keywords" placeholder="Add a keyword and press Enter…" />
        <FieldHint>Used for scoring context and the Analytics "best job types" chart.</FieldHint>
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
