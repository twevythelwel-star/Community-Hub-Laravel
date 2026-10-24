import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Check, ChevronsUpDown } from 'lucide-react';
import { DateField } from '@/components/dashboard/date-field';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';


const predefinedReasons = [
    'Security Threat',
    'Prior Misconduct',
    'Restraining Order',
    'Policy Violation',
    'Other',
];

const formSchema = z.object({
  name: z.string({ required_error: "Please select a visitor."}),
  reason: z.string().min(1, 'A reason is required.'),
  customReason: z.string().optional(),
  photoUrl: z.string().url().nullable().optional(),
  isPermanent: z.boolean().default(true),
  expiryDate: z.date().optional().nullable(),
}).refine(data => data.isPermanent || !!data.expiryDate, {
    message: "An expiry date is required for temporary blocks.",
    path: ["expiryDate"],
}).refine(data => data.reason !== 'Other' || (data.reason === 'Other' && data.customReason && data.customReason.length >= 10), {
    message: 'A custom reason must be at least 10 characters long.',
    path: ['customReason'],
});

type FormValues = z.infer<typeof formSchema>;

/**
 * What the caller receives on save. The free-text "Other" reason is already
 * resolved into `reason`, so the caller never has to know about customReason.
 */
export type BlocklistFormValues = {
  name: string;
  reason: string;
  photoUrl: string | null;
  expiryDate: Date | string | null;
};

/**
 * Existing entry to edit. Loosened from the mock `BlocklistEntry`: server rows
 * carry a numeric id and ISO date strings where the mock used a string id and a
 * `Date`. `reason` is optional because the payload omits it for viewers who
 * cannot manage the list — though those viewers never reach this form.
 */
export type BlocklistFormSubject = {
  id: number;
  name: string;
  reason?: string;
  photoUrl?: string | null;
  expiryDate?: string | Date | null;
};

/** Visitor shortlist used to pre-fill a name and reuse an ID photo on file. */
export type BlocklistFormVisitor = {
  id: number | string;
  name: string;
  idImageUrl: string | null;
};

type BlocklistFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  // Numeric: the block-list routes are constrained with whereNumber().
  onSave: (entry: BlocklistFormValues, id?: number) => void;
  entry?: BlocklistFormSubject;
  visitors: BlocklistFormVisitor[];
};

export function BlocklistForm({ children, open, onOpenChange, onSave, entry, visitors }: BlocklistFormProps) {
  const [isVisitorPopoverOpen, setVisitorPopoverOpen] = useState(false);
  
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      name: '',
      reason: '',
      customReason: '',
      photoUrl: null,
      isPermanent: true,
      expiryDate: null,
    },
  });

  function resetValues(): FormValues {
    if (!entry) {
      return { name: '', reason: '', customReason: '', photoUrl: null, isPermanent: true, expiryDate: null };
    }
    const existingReason = entry.reason ?? '';
    const isPredefined = predefinedReasons.includes(existingReason);

    return {
      name: entry.name,
      reason: isPredefined ? existingReason : 'Other',
      customReason: isPredefined ? '' : existingReason,
      photoUrl: entry.photoUrl ?? null,
      isPermanent: !entry.expiryDate,
      // The server sends an ISO string; the date picker needs a Date.
      expiryDate: entry.expiryDate ? new Date(entry.expiryDate) : null,
    };
  }

  function onSubmit(values: FormValues) {
    const finalReason = values.reason === 'Other' ? values.customReason! : values.reason;
    const visitor = visitors.find(v => v.name === values.name);

    onSave(
      {
        name: values.name,
        reason: finalReason,
        // Reuse the ID photo already on file for a known visitor.
        photoUrl: visitor?.idImageUrl ?? values.photoUrl ?? null,
        expiryDate: values.isPermanent ? null : values.expiryDate!,
      },
      entry?.id,
    );

    /*
     * The success toast and the dialog close moved to the caller. Both used to
     * fire here, immediately after onSave — which was fine when onSave mutated
     * React state and could not fail, but would now announce success before the
     * server had accepted anything, and would hide the validation errors.
     */
  }

  return (
    <FormDialog
      trigger={children}
      open={open}
      onOpenChange={onOpenChange}
      form={form}
      onSubmit={onSubmit}
      record={entry}
      resetValues={resetValues}
      title={entry ? 'Edit Blocklist Entry' : 'Add to Blocklist'}
      description="Select a visitor and provide a reason to add them to the blocklist."
      submitLabel="Save Changes"
    >
      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem className="flex flex-col">
            <FormLabel>Visitor Name</FormLabel>
             <Popover open={isVisitorPopoverOpen} onOpenChange={setVisitorPopoverOpen}>
                  <PopoverTrigger asChild>
                      <FormControl>
                          <Button
                          variant="outline"
                          role="combobox"
                          className={cn(
                              "w-full justify-between",
                              !field.value && "text-muted-foreground"
                          )}
                          >
                          {field.value
                              ? visitors.find(
                                  (visitor) => visitor.name === field.value
                              )?.name
                              : "Select visitor"}
                          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                          </Button>
                      </FormControl>
                  </PopoverTrigger>
                  <PopoverContent className="w-[--radix-popover-trigger-width] p-0">
                      <Command>
                          <CommandInput placeholder="Search visitors..." />
                          <CommandList>
                          <CommandEmpty>No visitors found.</CommandEmpty>
                          <CommandGroup>
                              {visitors.map((visitor) => (
                              <CommandItem
                                  value={visitor.name}
                                  key={visitor.id}
                                  onSelect={() => {
                                  form.setValue("name", visitor.name);
                                  setVisitorPopoverOpen(false);
                                  }}
                              >
                                  <Check
                                  className={cn(
                                      "mr-2 h-4 w-4",
                                      visitor.name === field.value
                                      ? "opacity-100"
                                      : "opacity-0"
                                  )}
                                  />
                                  {visitor.name}
                              </CommandItem>
                              ))}
                          </CommandGroup>
                          </CommandList>
                      </Command>
                  </PopoverContent>
              </Popover>
            <FormMessage />
          </FormItem>
        )}
      />
      
       <FormField
          control={form.control}
          name="reason"
          render={({ field }) => (
              <FormItem>
                  <FormLabel>Reason for Block</FormLabel>
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                      <FormControl>
                          <SelectTrigger>
                              <SelectValue placeholder="Select a reason" />
                          </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                          {predefinedReasons.map(reason => (
                              <SelectItem key={reason} value={reason}>{reason}</SelectItem>
                          ))}
                      </SelectContent>
                  </Select>
                  <FormMessage />
              </FormItem>
          )}
      />

      {form.watch('reason') === 'Other' && (
        <TextField control={form.control} name="customReason" label="Custom Reason" placeholder="Explain the specific reason..." multiline />
      )}

      <FormField
        control={form.control}
        name="isPermanent"
        render={({ field }) => (
          <FormItem className="flex flex-row items-center justify-between rounded-lg border p-3 shadow-sm">
            <div className="space-y-0.5">
              <FormLabel>Permanent Block</FormLabel>
               <FormMessage />
            </div>
            <FormControl>
              <Switch
                checked={field.value}
                onCheckedChange={field.onChange}
              />
            </FormControl>
          </FormItem>
        )}
      />

      {!form.watch('isPermanent') && (
        <DateField control={form.control} name="expiryDate" label="Expiry Date" disabled={(date) => date < new Date()} />
      )}
    </FormDialog>
  );
}
