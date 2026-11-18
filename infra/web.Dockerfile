FROM node:20-alpine AS build
WORKDIR /app
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_REVERB_APP_KEY
ARG NEXT_PUBLIC_REVERB_HOST
ARG NEXT_PUBLIC_REVERB_PORT
ENV NEXT_TELEMETRY_DISABLED=1
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM node:20-alpine
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1
COPY --from=build /app/package.json /app/package-lock.json ./
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/.next ./.next
COPY --from=build /app/public ./public
EXPOSE 3030
CMD ["npx", "next", "start", "-p", "3030"]
