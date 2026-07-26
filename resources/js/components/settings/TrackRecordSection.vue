<script setup>
/**
 * The workspace's OWN track record — the four things a proposal needs that
 * nobody else can supply.
 *
 * These live here rather than in Proposals & Facts because that tab is the
 * product's methodology (how a proposal is written, the quality gate, the
 * account stage) and belongs to the platform owner. This is data: what THIS
 * workspace has actually built, and the name it signs off with. Folding the
 * two together meant hiding the methodology also hid a workspace's own facts,
 * which left proposals permanently paused with a banner pointing at a screen
 * its owner could not open.
 */
import { reactive, ref, watch } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const form = reactive({
  project_facts: props.settings.project_facts ?? "",
  proposal_signature: props.settings.proposal_signature ?? "",
  proposal_min_words: props.settings.proposal_min_words ?? 90,
  proposal_max_words: props.settings.proposal_max_words ?? 150,
});

watch(
  () => props.settings,
  (value) => Object.assign(form, {
    project_facts: value.project_facts ?? "",
    proposal_signature: value.proposal_signature ?? "",
    proposal_min_words: value.proposal_min_words ?? 90,
    proposal_max_words: value.proposal_max_words ?? 150,
  }),
);

const saving = ref(false);

async function save() {
  saving.value = true;
  try {
    await saveSettings({ ...form });
    toast.success("Saved. Proposals can use these from the next rewrite.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Your track record</CardTitle>
    </CardHeader>
    <CardContent class="space-y-5">
      <CardDescription>
        What this workspace has actually built, and the name its proposals sign off with. A
        proposal may only claim what this sheet backs — anything not written here is treated as
        not in the track record and will never be mentioned.
      </CardDescription>

      <div>
        <Label>Project facts</Label>
        <textarea
          v-model="form.project_facts"
          rows="14"
          class="w-full rounded-md border border-border-strong bg-white p-3 font-mono text-xs leading-relaxed text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          placeholder="One project per line. What it was, what you built, which stack.&#10;&#10;Northwind brand system: identity and packaging for a food brand. Figma, Illustrator. Logo, type scale, 40-page guidelines.&#10;Acme app icons: iOS and Android icon set, 60 assets across 4 densities."
        />
        <FieldHint>
          One project per line, plainly. Name it, say what you did, name the stack. The proposal
          writer draws only on this — a project you leave out simply will not be claimed.
        </FieldHint>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <div>
          <Label>Sign off as</Label>
          <Input v-model="form.proposal_signature" placeholder="Your first name" />
          <FieldHint>The exact last line of every proposal.</FieldHint>
        </div>
        <div>
          <Label>Min words</Label>
          <Input type="number" v-model.number="form.proposal_min_words" />
        </div>
        <div>
          <Label>Max words</Label>
          <Input type="number" v-model.number="form.proposal_max_words" />
        </div>
      </div>

      <div class="flex justify-end">
        <Button :loading="saving" @click="save">Save</Button>
      </div>
    </CardContent>
  </Card>
</template>
