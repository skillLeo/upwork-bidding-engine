import { clsx } from "clsx";
import { twMerge } from "tailwind-merge";
import {
  formatDistanceToNow,
  format,
  differenceInMinutes,
  differenceInHours,
  differenceInDays,
} from "date-fns";

export function cn(...inputs) {
  return twMerge(clsx(inputs));
}

export function relativeTime(value) {
  if (!value) return "—";
  try {
    return formatDistanceToNow(new Date(value), { addSuffix: true });
  } catch {
    return "—";
  }
}

export function formatDateTime(value) {
  if (!value) return "—";
  try {
    return format(new Date(value), "MMM d, yyyy 'at' h:mm a");
  } catch {
    return "—";
  }
}

export function formatDate(value) {
  if (!value) return "—";
  try {
    return format(new Date(value), "MMM d, yyyy");
  } catch {
    return "—";
  }
}

// Score tier drives both the row's colour rail and the score cell's text
// colour — the one signal a bidder should never have to read the number for.
export function scoreTier(score) {
  if (score == null) return "neutral";
  if (score >= 9) return "success";
  if (score >= 7) return "info";
  return "neutral";
}

// Freshness is the #1 win factor for bidding, so the age column gets a
// compact, colour-coded value instead of a full "posted 3 hours ago" string
// — this table is scanned, not read. Title attribute keeps the full phrase
// for anyone who hovers.
export function compactAge(postedAt) {
  if (!postedAt) return { tier: "normal", label: "—", title: "—" };
  const date = new Date(postedAt);
  const minutes = differenceInMinutes(new Date(), date);
  const hours = differenceInHours(new Date(), date);
  const days = differenceInDays(new Date(), date);
  const title = `Posted ${relativeTime(postedAt)}`;

  let label;
  if (minutes < 60) label = `${Math.max(minutes, 0)}m`;
  else if (hours < 24) label = `${hours}h`;
  else if (days < 14) label = `${days}d`;
  else label = `${Math.floor(days / 7)}w`;

  const tier = hours < 2 ? "fresh" : days < 3 ? "normal" : "stale";
  return { tier, label, title };
}

export function initials(name) {
  const parts = (name || "").trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
