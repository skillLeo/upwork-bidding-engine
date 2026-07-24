<script setup>
import { onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const token = route.query.token;
const loading = ref(true);
const invalid = ref(false);
const info = ref(null);
const name = ref("");
const password = ref("");
const passwordConfirm = ref("");
const submitting = ref(false);

onMounted(async () => {
  if (!token) {
    invalid.value = true;
    loading.value = false;
    return;
  }
  try {
    const res = await apiClient.get("/invitations/show", { params: { token } });
    info.value = res.data.data;
  } catch {
    invalid.value = true;
  } finally {
    loading.value = false;
  }
});

async function accept() {
  submitting.value = true;
  try {
    const payload = { token };
    if (info.value.needs_account) {
      payload.name = name.value;
      payload.password = password.value;
      payload.password_confirmation = passwordConfirm.value;
    }
    const res = await apiClient.post("/invitations/accept", payload);
    // Land signed in.
    auth.setAuth(res.data.data.token, null, true);
    const me = await apiClient.get("/me");
    auth.setUser(me.data.data);
    toast.success(res.data.data.message);
    router.push("/leads");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not accept the invitation."));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-bg px-4">
    <Card class="w-full max-w-md">
      <CardContent class="space-y-4 p-6">
        <div v-if="loading" class="py-8 text-center text-sm text-text-secondary">Checking your invitation…</div>

        <div v-else-if="invalid" class="space-y-3 py-4 text-center">
          <p class="text-base font-semibold text-text-primary">This invitation is invalid or expired</p>
          <p class="text-sm text-text-secondary">Ask whoever invited you to send a fresh one.</p>
          <Button variant="secondary" @click="router.push('/login')">Go to sign in</Button>
        </div>

        <template v-else>
          <div>
            <h1 class="text-lg font-semibold text-text-primary">Join {{ info.workspace }}</h1>
            <p class="mt-1 text-sm text-text-secondary">
              {{ info.invited_by ? `${info.invited_by} invited you` : "You've been invited" }}
              as a <strong>{{ info.role }}</strong> ({{ info.email }}).
            </p>
          </div>

          <template v-if="info.needs_account">
            <div>
              <Label>Your name</Label>
              <Input v-model="name" type="text" placeholder="Your name" />
            </div>
            <div>
              <Label>Password</Label>
              <Input v-model="password" type="password" autocomplete="new-password" />
            </div>
            <div>
              <Label>Confirm password</Label>
              <Input v-model="passwordConfirm" type="password" autocomplete="new-password" />
            </div>
          </template>
          <p v-else class="text-sm text-text-secondary">
            You already have a SkillLeo account with this email — accepting adds this workspace to it.
          </p>

          <Button class="w-full" :loading="submitting" @click="accept">
            {{ info.needs_account ? "Create account and join" : "Join workspace" }}
          </Button>
        </template>
      </CardContent>
    </Card>
  </div>
</template>
