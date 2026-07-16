<script setup>
import { ref } from "vue";
import { toast } from "vue-sonner";
import { Trash2, Upload } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useBrandingStore } from "@/stores/branding";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);
const branding = useBrandingStore();

const appName = ref(props.settings.app_name ?? "SkillLeo");
const savingName = ref(false);
const uploadingLogo = ref(false);
const removingLogo = ref(false);
const fileInput = ref(null);

async function handleSaveName() {
  savingName.value = true;
  try {
    await saveSettings({ app_name: appName.value });
    await branding.fetch();
    toast.success("Product name saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save the product name."));
  } finally {
    savingName.value = false;
  }
}

function pickLogo() {
  fileInput.value?.click();
}

async function handleLogoChange(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  uploadingLogo.value = true;
  try {
    const form = new FormData();
    form.append("logo", file);
    await apiClient.post("/settings/logo", form, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    await branding.fetch();
    toast.success("Logo updated.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not upload the logo — try a PNG, JPG, or WebP under 2MB."));
  } finally {
    uploadingLogo.value = false;
    event.target.value = "";
  }
}

async function handleRemoveLogo() {
  removingLogo.value = true;
  try {
    await apiClient.delete("/settings/logo");
    await branding.fetch();
    toast.success("Logo removed.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not remove the logo."));
  } finally {
    removingLogo.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Branding</CardTitle>
    </CardHeader>
    <CardContent class="space-y-5">
      <CardDescription>
        Shown in the nav bar and on the sign-in screen for everyone who uses this app.
      </CardDescription>

      <div>
        <Label>Product name</Label>
        <div class="flex gap-2">
          <Input v-model="appName" placeholder="SkillLeo" class="max-w-xs" />
          <Button :loading="savingName" @click="handleSaveName">Save name</Button>
        </div>
      </div>

      <div>
        <Label>Logo</Label>
        <div class="flex items-center gap-4">
          <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-neutral-bg">
            <img
              v-if="branding.logoUrl"
              :src="branding.logoUrl"
              alt="Current logo"
              class="h-full w-full object-contain"
            />
            <span v-else class="text-xs text-text-tertiary">None</span>
          </div>
          <div class="flex gap-2">
            <Button variant="secondary" size="sm" :loading="uploadingLogo" @click="pickLogo">
              <Upload class="h-3.5 w-3.5" /> Upload logo
            </Button>
            <Button
              v-if="branding.logoUrl"
              variant="ghost"
              size="sm"
              :loading="removingLogo"
              @click="handleRemoveLogo"
            >
              <Trash2 class="h-3.5 w-3.5" /> Remove
            </Button>
          </div>
          <input
            ref="fileInput"
            type="file"
            accept=".png,.jpg,.jpeg,.webp"
            class="hidden"
            @change="handleLogoChange"
          />
        </div>
        <p class="mt-2 text-xs text-text-tertiary">PNG, JPG, or WebP, up to 2MB. No logo yet? The product name's initials show instead.</p>
      </div>
    </CardContent>
  </Card>
</template>
