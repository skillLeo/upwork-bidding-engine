import { apiClient } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth-store";
import type { User } from "@/lib/types";

export async function login(email: string, password: string): Promise<User> {
  const res = await apiClient.post<{ data: { token: string; user: User } }>(
    "/auth/login",
    { email, password },
  );
  useAuthStore.getState().setAuth(res.data.data.token, res.data.data.user);
  return res.data.data.user;
}

export async function logout(): Promise<void> {
  try {
    await apiClient.post("/auth/logout");
  } catch {
    // token may already be invalid — still clear local state below
  } finally {
    useAuthStore.getState().logout();
  }
}
