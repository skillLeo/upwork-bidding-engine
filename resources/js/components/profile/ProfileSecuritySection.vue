<script setup>
import { ref, watch } from "vue";
import { toast } from "vue-sonner";
import { ShieldCheck } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();
const enabled = ref(!!auth.user?.two_factor_enabled);
const saving = ref(false);

watch(
  () => auth.user?.two_factor_enabled,
  (value) => {
    enabled.value = !!value;
  },
);

async function handleToggle(event) {
  const next = event.target.checked;
  saving.value = true;
  try {
    const res = await apiClient.put("/profile/two-factor", { enabled: next });
    auth.setUser(res.data.data);
    toast.success(next ? "Two-factor login enabled." : "Two-factor login disabled.");
  } catch (error) {
    enabled.value = !next;
    toast.error(apiErrorMessage(error, "Could not update two-factor setting."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <ShieldCheck class="h-4 w-4 text-primary" /> Two-factor login
      </CardTitle>
    </CardHeader>
    <CardContent class="space-y-3">
      <CardDescription>
        When on, signing in with your password isn't enough — we also email a 6-digit code that
        must be entered before you're let in.
      </CardDescription>
      <label class="flex items-center gap-2 text-sm text-text-secondary">
        <input
          type="checkbox"
          :checked="enabled"
          @change="handleToggle"
          :disabled="saving"
          class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
        />
        Require an emailed code when signing in
      </label>
    </CardContent>
  </Card>
</template>
