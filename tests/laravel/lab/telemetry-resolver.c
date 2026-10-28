#define _GNU_SOURCE
#include <dlfcn.h>
#include <netdb.h>
#include <string.h>

/* Lab-only: packaged FPM performs this DNS lookup before reading pool config.
 * Keep disposable qualification independent of that external telemetry server.
 * Every other lookup (including all application connections) uses libc unchanged.
 */
int getaddrinfo(const char *node, const char *service,
                const struct addrinfo *hints, struct addrinfo **result)
{
    if (node && strcmp(node, "telemetry.sury.org") == 0) return EAI_NONAME;
    int (*next)(const char *, const char *, const struct addrinfo *, struct addrinfo **) =
        dlsym(RTLD_NEXT, "getaddrinfo");
    if (!next) return EAI_SYSTEM;
    return next(node, service, hints, result);
}
