<script setup>
import { ref, computed } from "vue";
import { toast } from "vue-sonner";
import { Camera } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";
import Avatar from "@/components/ui/Avatar.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();

const name = ref(auth.user?.name ?? "");
const email = ref(auth.user?.email ?? "");
const errors = ref({});
const saving = ref(false);
const uploadingAvatar = ref(false);
const fileInput = ref(null);

const avatarSrc = computed(() => auth.user?.avatar_url ?? null);

async function handleSave() {
  errors.value = {};
  saving.value = true;
  try {
    const res = await apiClient.put("/profile", { name: name.value, email: email.value });
    auth.setUser(res.data.data);
    toast.success("Profile updated.");
  } catch (error) {
    errors.value = error.response?.data?.errors
      ? Object.fromEntries(Object.entries(error.response.data.errors).map(([k, v]) => [k, v[0]]))
      : {};
    toast.error(apiErrorMessage(error, "Could not update profile."));
  } finally {
    saving.value = false;
  }
}

function pickAvatar() {
  fileInput.value?.click();
}

async function handleAvatarChange(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  uploadingAvatar.value = true;
  const formData = new FormData();
  formData.append("avatar", file);

  try {
    const res = await apiClient.post("/profile/avatar", formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    auth.setUser(res.data.data);
    toast.success("Profile photo updated.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not upload photo."));
  } finally {
    uploadingAvatar.value = false;
    event.target.value = "";
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Profile</CardTitle>
    </CardHeader>
    <CardContent class="space-y-5">
      <div class="flex items-center gap-4">
        <button
          type="button"
          @click="pickAvatar"
          class="group relative"
          :disabled="uploadingAvatar"
          aria-label="Change profile photo"
        >
          <Avatar :name="auth.user?.name ?? '?'" :src="avatarSrc" size="lg" />
          <span
            class="absolute inset-0 flex items-center justify-center rounded-full bg-black/40 text-white opacity-0 transition-opacity group-hover:opacity-100"
          >
            <Camera class="h-5 w-5" />
          </span>
        </button>
        <div>
          <Button type="button" variant="secondary" size="sm" :loading="uploadingAvatar" @click="pickAvatar">
            Change photo
          </Button>
          <p class="mt-1 text-xs text-text-tertiary">JPG or PNG, up to 4MB.</p>
        </div>
        <input
          ref="fileInput"
          type="file"
          accept="image/*"
          class="hidden"
          @change="handleAvatarChange"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>Name</Label>
          <Input v-model="name" />
          <FieldError :text="errors.name" />
        </div>
        <div>
          <Label>Email</Label>
          <Input type="email" v-model="email" />
          <FieldError :text="errors.email" />
        </div>
      </div>

      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save profile</Button>
      </div>
    </CardContent>
  </Card>
</template>
