<script setup>
import { useRoute } from "vue-router";
import { ShieldAlert } from "@lucide/vue";
import PageContainer from "@/components/layout/PageContainer.vue";
import ProfileBasicInfoSection from "@/components/profile/ProfileBasicInfoSection.vue";
import ProfilePasswordSection from "@/components/profile/ProfilePasswordSection.vue";
import ProfileSecuritySection from "@/components/profile/ProfileSecuritySection.vue";
import ProfileSessionsSection from "@/components/profile/ProfileSessionsSection.vue";
import NotificationPreferencesSection from "@/components/profile/NotificationPreferencesSection.vue";
import MyWorkspacesSection from "@/components/profile/MyWorkspacesSection.vue";
import ConnectedAccountsSection from "@/components/profile/ConnectedAccountsSection.vue";

const route = useRoute();
</script>

<template>
  <PageContainer class="max-w-[760px] space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-text-primary">Your profile</h1>
      <p class="text-sm text-text-secondary">Manage your name, email, photo, and sign-in security.</p>
    </div>
    <div v-if="route.query.enroll2fa" class="flex items-start gap-2.5 rounded-card border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text-secondary">
      <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0 text-warning" />
      <p>
        This workspace requires two-factor authentication. Set up an authenticator app below to
        continue — nothing else is reachable until you do.
      </p>
    </div>
    <ProfileBasicInfoSection />
    <ProfilePasswordSection />
    <ProfileSecuritySection />
    <ConnectedAccountsSection />
    <ProfileSessionsSection />
    <NotificationPreferencesSection />
    <MyWorkspacesSection />
  </PageContainer>
</template>
