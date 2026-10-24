import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
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
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../ui/select';

/**
 * Create or edit a community account.
 *
 * The form had no password fields, but `DirectoryController::storeUser()`
 * requires `password` + `password_confirmation` — so a create could never have
 * satisfied the server even once the button was wired up. The fields are here
 * now, and only on create: editing goes to `updateUser`, which does not touch
 * credentials.
 *
 * An administrator setting the initial password is not ideal — an emailed
 * invite would be better — but this application has no mail transport
 * configured, so it is the only flow that works end to end today.
 */

const creatableRoles = ['Homeowner', 'Temporary Homeowner', 'Security', 'Admin'] as const;

export type DirectoryUser = {
  id: number;
  name: string;
  email: string | null;
  role: string;
  status: 'Active' | 'Inactive';
  lot: string | null;
  street: string | null;
  /** What they own; HOA dues are charged per property. */
  properties?: { id: number; label: string; code: string }[];
  isSelf: boolean;
};

const baseFields = {
  name: z.string().min(2, 'Name must be at least 2 characters.'),
  email: z.string().email('Please enter a valid email address.'),
  role: z.enum(creatableRoles),
  status: z.enum(['Active', 'Inactive']),
  lotNumber: z.string().optional(),
  streetName: z.string().optional(),
};

const addressRule = {
  message: 'Lot number and street name are required for Homeowners and Renters.',
  // Not `as const`: zod's refine wants a mutable (string | number)[] path.
  path: ['lotNumber'],
};

const needsAddress = (data: { role: string; lotNumber?: string; streetName?: string }) =>
  !(
    (data.role === 'Homeowner' || data.role === 'Temporary Homeowner') &&
    (!data.lotNumber || !data.streetName)
  );

const editSchema = z.object(baseFields).refine(needsAddress, addressRule);

const createSchema = z
  .object({
    ...baseFields,
    // Laravel's Password::defaults() is 8 characters unless configured otherwise.
    password: z.string().min(8, 'Password must be at least 8 characters.'),
    passwordConfirmation: z.string(),
  })
  .refine(needsAddress, addressRule)
  .refine((data) => data.password === data.passwordConfirmation, {
    message: 'The two passwords do not match.',
    path: ['passwordConfirmation'],
  });

export type UserFormValues = z.infer<typeof createSchema>;

type CreateUserFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSave: (values: UserFormValues, id?: number) => void;
  userToEdit?: DirectoryUser;
  submitting?: boolean;
};

export function CreateUserForm({
  children,
  open,
  onOpenChange,
  onSave,
  userToEdit,
  submitting = false,
}: CreateUserFormProps) {
  const isEdit = userToEdit !== undefined;

  const form = useForm<UserFormValues>({
    resolver: zodResolver(isEdit ? (editSchema as unknown as typeof createSchema) : createSchema),
    defaultValues: {
      name: '',
      email: '',
      role: 'Homeowner',
      status: 'Active',
      lotNumber: '',
      streetName: '',
      password: '',
      passwordConfirmation: '',
    },
  });

  useEffect(() => {
    if (!open) {
      return;
    }

    form.reset({
      name: userToEdit?.name ?? '',
      email: userToEdit?.email ?? '',
      role: (userToEdit?.role as (typeof creatableRoles)[number]) ?? 'Homeowner',
      status: userToEdit?.status ?? 'Active',
      lotNumber: userToEdit?.lot ?? '',
      streetName: userToEdit?.street ?? '',
      password: '',
      passwordConfirmation: '',
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, userToEdit]);

  const selectedRole = form.watch('role');
  const showAddressFields =
    selectedRole === 'Homeowner' || selectedRole === 'Temporary Homeowner';

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="sm:max-w-[425px]">
        <Form {...form}>
          <form
            onSubmit={form.handleSubmit((values) => onSave(values, userToEdit?.id))}
            className="space-y-4"
          >
            <DialogHeader>
              <DialogTitle>{isEdit ? 'Edit User' : 'Create New User'}</DialogTitle>
              <DialogDescription>
                {isEdit
                  ? `Update the profile details for ${userToEdit.name}.`
                  : 'Fill out the form to create a new user account.'}
              </DialogDescription>
            </DialogHeader>

            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Full Name</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., Jane Doe" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Email Address</FormLabel>
                  <FormControl>
                    {/*
                      The email is the sign-in identity and updateUser does not
                      accept a change to it, so editing it here would be a field
                      whose value the server silently drops.
                    */}
                    <Input
                      type="email"
                      placeholder="e.g., jane.d@example.com"
                      disabled={isEdit}
                      {...field}
                    />
                  </FormControl>
                  {isEdit && (
                    <FormDescription>
                      The sign-in address cannot be changed here.
                    </FormDescription>
                  )}
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className={isEdit ? 'grid grid-cols-2 gap-4' : ''}>
              <FormField
                control={form.control}
                name="role"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Role</FormLabel>
                    <Select onValueChange={field.onChange} value={field.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {creatableRoles.map((role) => (
                          <SelectItem key={role} value={role}>
                            {role}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {/*
                Status only on edit. `storeUser` hardcodes 'Active' and never
                reads a status off the request, so offering the choice at
                creation was a control whose value the server dropped.
              */}
              {isEdit && (
              <FormField
                control={form.control}
                name="status"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Status</FormLabel>
                    <Select
                      onValueChange={field.onChange}
                      value={field.value}
                      disabled={userToEdit?.isSelf}
                    >
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder="Select a status" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="Active">Active</SelectItem>
                        <SelectItem value="Inactive">Inactive</SelectItem>
                      </SelectContent>
                    </Select>
                    {userToEdit?.isSelf && (
                      <FormDescription>You cannot deactivate yourself.</FormDescription>
                    )}
                    <FormMessage />
                  </FormItem>
                )}
              />
              )}
            </div>

            {showAddressFields && (
              <div className="grid grid-cols-2 gap-4">
                <FormField
                  control={form.control}
                  name="lotNumber"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Lot Number</FormLabel>
                      <FormControl>
                        <Input placeholder="e.g., 42" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="streetName"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Street Name</FormLabel>
                      <FormControl>
                        <Input placeholder="e.g., Main St" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
            )}

            {!isEdit && (
              <div className="grid grid-cols-2 gap-4">
                <FormField
                  control={form.control}
                  name="password"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Initial Password</FormLabel>
                      <FormControl>
                        <Input type="password" autoComplete="new-password" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="passwordConfirmation"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Confirm Password</FormLabel>
                      <FormControl>
                        <Input type="password" autoComplete="new-password" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
            )}

            {!isEdit && (
              <p className="text-xs text-muted-foreground">
                Hand this password to the account holder and ask them to change it after their
                first sign-in.
              </p>
            )}

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Saving…' : 'Save Changes'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
