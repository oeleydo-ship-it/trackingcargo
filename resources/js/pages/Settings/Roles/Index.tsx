import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { Permission, Role } from '../../../types';

interface RolesIndexProps {
    roles: Role[];
    permissions: Permission[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function RolesIndex({ roles, permissions }: RolesIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const grouped = useMemo(() => {
        return permissions.reduce<Record<string, Permission[]>>((groups, permission) => {
            (groups[permission.group] ??= []).push(permission);
            return groups;
        }, {});
    }, [permissions]);

    const startCreate = () => {
        setEditingId(null);
        setShowForm((value) => !value);
    };

    const startEdit = (role: Role) => {
        setShowForm(false);
        setEditingId((current) => (current === role.id ? null : role.id));
    };

    const remove = (role: Role) => {
        if (confirm(`Remove role "${role.name}"?`)) {
            router.delete(`/settings/roles/${role.id}`);
        }
    };

    const editingRole = roles.find((role) => role.id === editingId) ?? null;

    return (
        <SettingsLayout title="Roles & permissions">
            <Head title="Roles & permissions" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{roles.length} role{roles.length === 1 ? '' : 's'}</p>
                <button onClick={startCreate} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New role'}
                </button>
            </div>

            {showForm && (
                <div className="mb-6 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                    <RoleForm grouped={grouped} onDone={() => setShowForm(false)} />
                </div>
            )}

            {editingRole && (
                <div className="mb-6 rounded-2xl border border-cyan-400/20 bg-cyan-400/[0.04] p-6">
                    <p className="mb-4 text-sm font-semibold text-cyan-200">Editing {editingRole.name}</p>
                    <RoleForm key={editingRole.id} role={editingRole} grouped={grouped} onDone={() => setEditingId(null)} />
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {roles.map((role) => (
                    <article key={role.id} className={`rounded-2xl border bg-white/[0.035] p-5 ${editingId === role.id ? 'border-cyan-400/40' : 'border-white/10'}`}>
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="font-medium">{role.name}</p>
                                <p className="text-xs text-slate-500">{role.slug}</p>
                            </div>
                            {role.is_system && <span className="rounded-full border border-white/10 px-2 py-0.5 text-[10px] uppercase tracking-wider text-slate-500">System</span>}
                        </div>
                        <p className="mt-4 text-xs text-slate-500">{role.permissions.length} permission{role.permissions.length === 1 ? '' : 's'}</p>
                        <div className="mt-3 space-x-3">
                            <button onClick={() => startEdit(role)} className="text-xs text-cyan-300 hover:text-cyan-200">
                                {editingId === role.id ? 'Cancel' : 'Edit'}
                            </button>
                            {!role.is_system && (
                                <button onClick={() => remove(role)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                            )}
                        </div>
                    </article>
                ))}
            </div>
        </SettingsLayout>
    );
}

function RoleForm({ role, grouped, onDone }: { role?: Role; grouped: Record<string, Permission[]>; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: role?.name ?? '',
        slug: role?.slug ?? '',
        permissions: role?.permissions.map((permission) => permission.id) ?? [],
    });

    const togglePermission = (id: number) => {
        setData('permissions', data.permissions.includes(id) ? data.permissions.filter((value) => value !== id) : [...data.permissions, id]);
    };

    const toggleGroup = (groupPermissions: Permission[]) => {
        const ids = groupPermissions.map((permission) => permission.id);
        const allSelected = ids.every((id) => data.permissions.includes(id));

        setData('permissions', allSelected
            ? data.permissions.filter((id) => !ids.includes(id))
            : [...new Set([...data.permissions, ...ids])]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const onSuccess = () => {
            reset();
            onDone();
        };

        if (role) {
            patch(`/settings/roles/${role.id}`, { onSuccess });
        } else {
            post('/settings/roles', { onSuccess });
        }
    };

    const id = (field: string) => (role ? `role-${role.id}-${field}` : `role-new-${field}`);

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={id('name')} className={labelClass}>Name</label>
                    <input id={id('name')} value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                    {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                </div>
                <div>
                    <label htmlFor={id('slug')} className={labelClass}>Slug</label>
                    <input
                        id={id('slug')}
                        value={data.slug}
                        onChange={(event) => setData('slug', event.target.value.toLowerCase().replace(/\s+/g, '-'))}
                        required
                        disabled={role?.is_system}
                        className={`${fieldClass} disabled:cursor-not-allowed disabled:opacity-50`}
                    />
                    {errors.slug && <p className="mt-1 text-xs text-rose-400">{errors.slug}</p>}
                    {role?.is_system && <p className="mt-1 text-xs text-slate-600">A system role's slug is referenced in code and cannot be changed.</p>}
                </div>
            </div>
            <div>
                <p className={labelClass}>Permissions</p>
                {errors.permissions && <p className="mb-2 text-xs text-rose-400">{errors.permissions}</p>}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {Object.entries(grouped).map(([group, groupPermissions]) => (
                        <div key={group} className="rounded-xl border border-white/10 p-3">
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{group}</p>
                                <button type="button" onClick={() => toggleGroup(groupPermissions)} className="text-[10px] uppercase tracking-wider text-cyan-300 hover:text-cyan-200">
                                    {groupPermissions.every((permission) => data.permissions.includes(permission.id)) ? 'None' : 'All'}
                                </button>
                            </div>
                            <div className="space-y-1.5">
                                {groupPermissions.map((permission) => (
                                    <label key={permission.id} className="flex items-center gap-2 text-xs text-slate-300">
                                        <input type="checkbox" checked={data.permissions.includes(permission.id)} onChange={() => togglePermission(permission.id)} className="size-3.5 rounded border-white/20 bg-white/5 text-cyan-400" />
                                        {permission.name}
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : role ? 'Save changes' : 'Create role'}
            </button>
        </form>
    );
}
