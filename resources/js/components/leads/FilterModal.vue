<script setup>
import { ref } from "vue";
import { X } from "@lucide/vue";
import { toast } from "vue-sonner";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import TagInput from "@/components/ui/TagInput.vue";
import { apiErrorMessage } from "@/lib/api-client";
import { createSavedFilter, updateSavedFilter, deleteSavedFilter } from "@/composables/useSavedFilters";

const props = defineProps({
  filter: { type: Object, default: null },
});
const emit = defineEmits(["close", "saved", "deleted"]);

const freshnessOptions = [
  { value: "", label: "Any time" },
  { value: "10", label: "Last 10 minutes" },
  { value: "30", label: "Last 30 minutes" },
  { value: "60", label: "Last hour" },
  { value: "180", label: "Last 3 hours" },
  { value: "1440", label: "Today" },
];

const name = ref(props.filter?.name ?? "");
const includeKeywords = ref(props.filter?.criteria.include_keywords ?? []);
const excludeKeywords = ref(props.filter?.criteria.exclude_keywords ?? []);
const budgetMin = ref(props.filter?.criteria.budget_min?.toString() ?? "");
const budgetMax = ref(props.filter?.criteria.budget_max?.toString() ?? "");
const paymentVerifiedOnly = ref(props.filter?.criteria.payment_verified_only ?? false);
const minClientSpend = ref(props.filter?.criteria.min_client_spend?.toString() ?? "");
const postedWithin = ref(props.filter?.criteria.posted_within_minutes?.toString() ?? "");
const isPinned = ref(props.filter?.is_pinned ?? true);
const isDefault = ref(props.filter?.is_default ?? false);
const saving = ref(false);
const deleting = ref(false);

async function handleSave() {
  if (!name.value.trim()) {
    toast.error("Give this filter a name.");
    return;
  }

  const input = {
    name: name.value.trim(),
    is_pinned: isPinned.value,
    is_default: isDefault.value,
    criteria: {
      include_keywords: includeKeywords.value,
      exclude_keywords: excludeKeywords.value,
      budget_min: budgetMin.value ? Number(budgetMin.value) : undefined,
      budget_max: budgetMax.value ? Number(budgetMax.value) : undefined,
      payment_verified_only: paymentVerifiedOnly.value || undefined,
      min_client_spend: minClientSpend.value ? Number(minClientSpend.value) : undefined,
      posted_within_minutes: postedWithin.value ? Number(postedWithin.value) : undefined,
    },
  };

  saving.value = true;
  try {
    const saved = props.filter
      ? await updateSavedFilter(props.filter.id, input)
      : await createSavedFilter(input);
    toast.success(props.filter ? "Filter updated." : "Filter created.");
    emit("saved", saved);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Couldn't save this filter."));
  } finally {
    saving.value = false;
  }
}

async function handleDelete() {
  if (!props.filter) return;
  deleting.value = true;
  try {
    await deleteSavedFilter(props.filter.id);
    toast.success("Filter deleted.");
    emit("deleted", props.filter.id);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Couldn't delete this filter."));
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-black/40" @click="emit('close')" />
    <div class="relative z-10 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-card border border-border bg-white shadow-popover">
      <div class="flex items-center justify-between border-b border-border px-5 py-4">
        <h2 class="text-base font-semibold text-text-primary">
          {{ filter ? "Edit filter" : "New filter" }}
        </h2>
        <button
          @click="emit('close')"
          class="rounded-full p-1 text-text-tertiary hover:bg-black/5 hover:text-text-primary"
          aria-label="Close"
        >
          <X class="h-4.5 w-4.5" />
        </button>
      </div>

      <div class="space-y-4 px-5 py-4">
        <div>
          <Label>Name</Label>
          <Input v-model="name" placeholder="e.g. PHP / Laravel" />
        </div>

        <div>
          <Label>Include keywords</Label>
          <TagInput v-model="includeKeywords" placeholder="Add a keyword and press Enter" />
        </div>

        <div>
          <Label>Exclude keywords</Label>
          <TagInput v-model="excludeKeywords" placeholder="Add a keyword and press Enter" />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <Label>Min budget ($)</Label>
            <Input type="number" min="0" v-model="budgetMin" placeholder="Any" />
          </div>
          <div>
            <Label>Max budget ($)</Label>
            <Input type="number" min="0" v-model="budgetMax" placeholder="Any" />
          </div>
        </div>

        <div>
          <Label>Min client spend ($)</Label>
          <Input type="number" min="0" v-model="minClientSpend" placeholder="Any" />
        </div>

        <div>
          <Label>Freshness</Label>
          <select
            v-model="postedWithin"
            class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option v-for="opt in freshnessOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>

        <label class="flex items-center gap-2 text-sm text-text-secondary">
          <input
            type="checkbox"
            v-model="paymentVerifiedOnly"
            class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
          />
          Payment verified clients only
        </label>

        <div class="flex gap-4 border-t border-border pt-4">
          <label class="flex items-center gap-2 text-sm text-text-secondary">
            <input
              type="checkbox"
              v-model="isPinned"
              class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
            />
            Pin to switcher
          </label>
          <label class="flex items-center gap-2 text-sm text-text-secondary">
            <input
              type="checkbox"
              v-model="isDefault"
              class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
            />
            Apply automatically on open
          </label>
        </div>
      </div>

      <div class="flex items-center justify-between border-t border-border px-5 py-4">
        <Button v-if="filter" variant="danger" size="sm" :loading="deleting" @click="handleDelete">
          Delete
        </Button>
        <span v-else />
        <div class="flex gap-2">
          <Button variant="ghost" size="sm" @click="emit('close')">Cancel</Button>
          <Button size="sm" :loading="saving" @click="handleSave">Save filter</Button>
        </div>
      </div>
    </div>
  </div>
</template>
