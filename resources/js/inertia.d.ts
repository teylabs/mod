export declare function resolveModulePage<T>(
    name: string,
    appPages: Record<string, Promise<T> | (() => Promise<T>)>,
    modulePages: Record<string, Promise<T> | (() => Promise<T>)>,
): Promise<T>;
export declare function resolveModulePage<T>(
    name: string,
    appPages: Record<string, T>,
    modulePages: Record<string, T>,
): Promise<T>;

/** Return the nearest glob value without invoking a loader or component. @api */
export declare function nearestPageFile<T>(
    name: string,
    files: Record<string, T>,
): T | undefined;
