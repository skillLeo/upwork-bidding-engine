import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import router from "@/router";

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? "/api",
  headers: {
    Accept: "application/json",
  },
});

apiClient.interceptors.request.use((config) => {
  const auth = useAuthStore();
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`;
  }
  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const auth = useAuthStore();
      auth.logout();
      if (router.currentRoute.value.name !== "login") {
        router.push({ name: "login" });
      }
    }
    return Promise.reject(error);
  },
);

export function apiErrorMessage(error, fallback = "Something went wrong.") {
  const body = error?.response?.data;
  if (body?.errors) {
    const first = Object.values(body.errors)[0]?.[0];
    if (first) return first;
  }
  if (body?.message) return body.message;
  return fallback;
}

export const fetcher = (url) => apiClient.get(url).then((res) => res.data);
