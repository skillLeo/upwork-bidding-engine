import * as React from "react";
import { cn } from "@/lib/utils";

export const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, ...props }, ref) => (
    <input
      ref={ref}
      className={cn(
        "h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-primary placeholder:text-text-tertiary",
        "focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20",
        "disabled:cursor-not-allowed disabled:bg-black/5 disabled:text-text-tertiary",
        className,
      )}
      {...props}
    />
  ),
);
Input.displayName = "Input";

export const Textarea = React.forwardRef<
  HTMLTextAreaElement,
  React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => (
  <textarea
    ref={ref}
    className={cn(
      "w-full rounded-md border border-border-strong bg-white px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary",
      "focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20",
      "disabled:cursor-not-allowed disabled:bg-black/5 disabled:text-text-tertiary",
      className,
    )}
    {...props}
  />
));
Textarea.displayName = "Textarea";

export function Label({ className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return (
    <label
      className={cn("mb-1.5 block text-sm font-medium text-text-primary", className)}
      {...props}
    />
  );
}

export function FieldHint({ className, ...props }: React.HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn("mt-1.5 text-xs text-text-tertiary", className)} {...props} />;
}

export function FieldError({ children }: { children?: string }) {
  if (!children) return null;
  return <p className="mt-1.5 text-xs font-medium text-danger">{children}</p>;
}
