import type { ReactNode } from 'react';
import type { Control, FieldPath, FieldValues } from 'react-hook-form';
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

type TextFieldProps<T extends FieldValues> = {
  control: Control<T>;
  name: FieldPath<T>;
  label: ReactNode;
  placeholder?: string;
  /** An <input> type such as "number" or "time"; ignored for multi-line fields. */
  type?: string;
  /** Render a textarea with this many rows (true for the default height). */
  multiline?: boolean | number;
  description?: ReactNode;
  descriptionClassName?: string;
  className?: string;
};

/** A labelled text input or textarea with its validation message. */
export function TextField<T extends FieldValues>({
  control,
  name,
  label,
  placeholder,
  type,
  multiline,
  description,
  descriptionClassName,
  className,
}: TextFieldProps<T>) {
  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => (
        <FormItem className={className}>
          <FormLabel>{label}</FormLabel>
          <FormControl>
            {multiline ? (
              <Textarea
                placeholder={placeholder}
                rows={typeof multiline === 'number' ? multiline : undefined}
                {...field}
                value={field.value ?? ''}
              />
            ) : (
              <Input type={type} placeholder={placeholder} {...field} value={field.value ?? ''} />
            )}
          </FormControl>
          {description && <FormDescription className={descriptionClassName}>{description}</FormDescription>}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}
