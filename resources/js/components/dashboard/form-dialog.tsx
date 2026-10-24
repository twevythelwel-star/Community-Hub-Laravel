import { useEffect, useRef, type ReactNode } from 'react';
import type { DefaultValues, FieldValues, UseFormReturn } from 'react-hook-form';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Form } from '@/components/ui/form';

type FormDialogProps<T extends FieldValues> = {
  /** The button that opens the dialog. */
  trigger: ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  form: UseFormReturn<T>;
  onSubmit: (values: T) => void | Promise<void>;
  /** The values the form starts from each time the dialog opens. */
  resetValues: () => DefaultValues<T>;
  /** The record being edited, if any: the form resets when it changes. */
  record?: unknown;
  title: ReactNode;
  description: ReactNode;
  submitLabel: string;
  /** Shown on the submit button while onSubmit is pending. */
  submittingLabel?: string;
  contentClassName?: string;
  /** The form's fields. */
  children: ReactNode;
};

/**
 * The dialog every dashboard form sits in: trigger, header, fields, and a
 * Cancel / Save footer whose Save is disabled while the save is pending.
 * The form is reset to resetValues() each time the dialog opens.
 */
export function FormDialog<T extends FieldValues>({
  trigger,
  open,
  onOpenChange,
  form,
  onSubmit,
  resetValues,
  record,
  title,
  description,
  submitLabel,
  submittingLabel,
  contentClassName = 'sm:max-w-md',
  children,
}: FormDialogProps<T>) {
  const resetRef = useRef(resetValues);
  resetRef.current = resetValues;

  useEffect(() => {
    if (open) {
      form.reset(resetRef.current());
    }
  }, [open, record, form]);

  const submitting = form.formState.isSubmitting;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className={contentClassName}>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <DialogHeader>
              <DialogTitle>{title}</DialogTitle>
              <DialogDescription>{description}</DialogDescription>
            </DialogHeader>

            {children}

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
              <Button type="submit" disabled={submitting}>
                {submitting && submittingLabel ? submittingLabel : submitLabel}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
